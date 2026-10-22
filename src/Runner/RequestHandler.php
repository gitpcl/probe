<?php

declare(strict_types=1);

namespace Probe\Runner;

use Probe\Phase\Toolbox;
use Probe\Probe;
use Probe\Result\Failure;
use Probe\Result\JsonResultEncoder;
use Probe\Result\ProbeResult;
use Probe\Session\SessionStore;
use Probe\Worker\SourceWatch;

/**
 * Agent tools handled inside a warm worker.
 * PHP probes stay on the warm path. Later tools share the same JSON result.
 */
final class RequestHandler
{
    private bool $stop = false;

    private int $probes = 0;

    public function __construct(
        private readonly SnippetRunner $snippets,
        private readonly SessionStore $session,
        private readonly JsonResultEncoder $encoder,
        private readonly DatabaseGuard $database,
        private readonly int $maxCodeBytes = 100000,
        private readonly int $recycleAfter = 500,
        private readonly ?Toolbox $toolbox = null,
        private readonly ?SourceWatch $watch = null,
        private readonly int $maxFailures = 20,
    ) {}

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function handle(array $request): array
    {
        $tool = isset($request['tool']) && is_string($request['tool']) && $request['tool'] !== ''
            ? $request['tool']
            : 'probe';

        $stale = $this->watch?->changed();
        if ($stale !== null && $tool !== 'ping' && $tool !== 'shutdown') {
            $this->stop = true;

            return [
                'status' => 'error',
                'duration_ms' => 0,
                'failures' => [['id' => 'stale', 'message' => $stale.' changed after the worker booted']],
                'stale' => $stale,
            ];
        }

        $result = match ($tool) {
            'ping' => ProbeResult::passed(0),
            'probe' => $this->probe($request),
            'get_failures' => $this->getFailures($request),
            'rerun_failed' => $this->rerunFailed($request),
            'shutdown' => $this->shutdown(),
            default => $this->dispatchTool($tool, $request),
        };

        if ($this->recycleAfter > 0 && $this->probes >= $this->recycleAfter) {
            $this->stop = true;
        }

        $payload = $tool === 'get_failures'
            ? $this->encoder->detailed($result)
            : $this->encoder->compact($result);

        // The session keeps every failure for get_failures --id; the run result lists the first few.
        if ($tool !== 'get_failures' && $this->maxFailures > 0 && count($result->failures) > $this->maxFailures) {
            $payload['failures'] = array_slice($payload['failures'], 0, $this->maxFailures);
            $payload['failures_total'] = count($result->failures);
        }

        if ($tool === 'ping') {
            $payload['database'] = $this->database->mode();
            $payload['version'] = Probe::VERSION;
            $payload['isolation'] = $this->snippets->isolation();
            if ($this->database->notice() !== null) {
                $payload['notice'] = $this->database->notice();
            }
            if ($stale !== null) {
                $payload['stale'] = $stale;
            }
            if ($this->snippets->isolation() === 'process' && ! function_exists('pcntl_fork')) {
                $payload['notice'] = trim(($payload['notice'] ?? '').' pcntl is not loaded: state set by one probe can leak into the next; use --cold to reset');
            }
        }

        return $payload;
    }

    public function shouldStop(): bool
    {
        return $this->stop;
    }

    public function isolation(): string
    {
        return $this->snippets->isolation();
    }

    /** @param  array<string, mixed>  $request */
    private function probe(array $request): ProbeResult
    {
        $code = $request['code'] ?? null;
        if (! is_string($code) || trim($code) === '') {
            return ProbeResult::error('code', 'probe requires code');
        }

        if (strlen($code) > $this->maxCodeBytes) {
            return ProbeResult::error('code', 'probe code exceeds the configured limit');
        }

        $timeout = isset($request['timeout_ms']) ? (int) $request['timeout_ms'] : null;
        $result = $this->snippets->run($code, $timeout);
        $this->remember('probe', $request, $result);

        return $result;
    }

    /** @param  array<string, mixed>  $request */
    private function getFailures(array $request): ProbeResult
    {
        $session = $this->session->get();
        $rows = is_array($session['failures'] ?? null) ? $session['failures'] : [];
        $total = count($rows);

        if (! isset($request['id']) || $request['id'] === '') {
            // One failure in full is the progressive-detail step; --id=N opens another.
            $rows = array_slice($rows, 0, 1);
        } else {
            $id = (string) $request['id'];
            $rows = array_values(array_filter(
                $rows,
                fn ($row): bool => is_array($row) && (string) ($row['id'] ?? '') === $id,
            ));

            if ($rows === []) {
                return ProbeResult::error('missing', 'no failure with that id');
            }
        }

        $failures = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $failures[] = Failure::fromArray($row);
            }
        }

        $status = (string) ($session['status'] ?? 'passed');
        if ($failures === []) {
            $status = 'passed';
        } elseif ($status === 'passed') {
            $status = 'failed';
        }

        return new ProbeResult(
            status: $status,
            durationMs: $failures === [] ? 0 : (int) ($session['duration_ms'] ?? 0),
            failures: $failures,
            value: ['shown' => count($failures), 'total' => $total],
            hasValue: true,
        );
    }

    /** @param  array<string, mixed>  $request */
    private function rerunFailed(array $request): ProbeResult
    {
        $session = $this->session->get();
        $code = $session['code'] ?? null;
        $status = (string) ($session['status'] ?? 'passed');

        $tool = (string) ($session['tool'] ?? 'probe');
        $replay = $session['replay'] ?? null;
        if ($tool !== 'probe' && $tool !== '' && is_array($replay) && $status === 'failed' && $this->toolbox !== null) {
            $result = $this->toolbox->handle($tool, $replay);
            if ($result === null) {
                return ProbeResult::error('tool', 'cannot rerun '.$tool);
            }

            $this->remember($tool, $replay, $result);

            return $result;
        }

        if (! is_string($code) || trim($code) === '' || $status !== 'failed') {
            return new ProbeResult(
                status: 'passed',
                durationMs: 0,
                failures: [],
                rerun: false,
            );
        }

        return $this->probe([
            'code' => $code,
            'timeout_ms' => $request['timeout_ms'] ?? null,
        ]);
    }

    private function shutdown(): ProbeResult
    {
        $this->stop = true;

        return ProbeResult::passed(0);
    }

    /** @param  array<string, mixed>  $request */
    private function dispatchTool(string $tool, array $request): ProbeResult
    {
        if ($this->toolbox === null) {
            return ProbeResult::error('tool', 'unknown tool '.$tool);
        }

        $result = $this->toolbox->handle($tool, $request);
        if ($result === null) {
            return ProbeResult::error('tool', 'unknown tool '.$tool);
        }

        $this->remember($tool, $request, $result);

        return $result;
    }

    /** @param  array<string, mixed>  $request */
    private function remember(string $tool, array $request, ProbeResult $result): void
    {
        $this->probes++;
        $code = $request['code'] ?? null;
        $this->session->put([
            'tool' => $tool,
            'code' => is_string($code) ? $code : null,
            'replay' => $request,
            'status' => $result->status,
            'duration_ms' => $result->durationMs,
            'failures' => array_map(
                fn (Failure $failure): array => $failure->detailed(),
                $result->failures,
            ),
            'has_value' => $result->hasValue,
            'value' => $result->hasValue ? $result->value : null,
        ]);
    }
}
