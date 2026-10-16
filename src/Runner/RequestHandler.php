<?php

declare(strict_types=1);

namespace Probe\Runner;

use Probe\Phase\Toolbox;
use Probe\Probe;
use Probe\Result\Failure;
use Probe\Result\JsonResultEncoder;
use Probe\Result\ProbeResult;
use Probe\Session\SessionStore;

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

        if ($tool === 'ping') {
            $payload['database'] = $this->database->mode();
            $payload['version'] = Probe::VERSION;
            if ($this->database->notice() !== null) {
                $payload['notice'] = $this->database->notice();
            }
        }

        return $payload;
    }

    public function shouldStop(): bool
    {
        return $this->stop;
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

        if (isset($request['id']) && $request['id'] !== null && $request['id'] !== '') {
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
