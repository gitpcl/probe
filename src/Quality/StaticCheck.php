<?php

declare(strict_types=1);

namespace Probe\Quality;

use Probe\Result\Failure;
use Probe\Result\ProbeResult;
use Probe\Selective\DependencyGraph;
use Probe\Selective\GitChanges;
use Probe\Support\Paths;
use Probe\Support\Process;
use Probe\Support\Stats;

/**
 * static_check. PHPStan (Larastan is a PHPStan extension) over the files a change
 * touches and the files that reference them, or over the project's own configured
 * paths. PHPStan keeps its own result cache; what Probe adds is the selection and
 * the result shape.
 */
final class StaticCheck
{
    /** @var list<string> */
    private const SCOPES = ['affected', 'changed', 'all'];

    /** @var list<string> */
    private const NEON = ['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'];

    /** @param  array<string, mixed>  $config  the static block */
    public function __construct(
        private readonly string $root,
        private readonly DependencyGraph $graph,
        private readonly array $config = [],
    ) {}

    /** @param  array<string, mixed>  $request */
    public function run(array $request): ProbeResult
    {
        $started = hrtime(true);

        $scope = is_string($request['scope'] ?? null) ? $request['scope'] : 'affected';
        if (! in_array($scope, self::SCOPES, true)) {
            return ProbeResult::error('scope', 'static_check scope is affected, changed or all');
        }

        $binary = $this->binary();
        if ($binary === null) {
            return ProbeResult::error('missing_tool', 'phpstan was not found; composer require --dev larastan/larastan (or phpstan/phpstan), or set PROBE_PHPSTAN');
        }

        $files = [];
        if ($scope === 'all') {
            if (! $this->hasConfig()) {
                return ProbeResult::error('config', 'scope all needs a phpstan.neon with paths; give paths or use scope affected');
            }
        } else {
            $paths = GitChanges::requested($this->root, $request['paths'] ?? null);
            if ($paths === []) {
                return ProbeResult::error('paths', 'static_check needs paths or a git diff, or scope all');
            }

            $files = $scope === 'affected' ? $this->graph->affectedSources($paths) : $this->existing($paths);
            if ($files === []) {
                return ProbeResult::passed(Stats::elapsedMs($started), ['files' => 0, 'errors' => 0, 'scope' => $scope], true);
            }
        }

        // Files named on the command line switch PHPStan's result cache off, so with a
        // neon the configured paths run (incremental) and the report is filtered instead.
        $filter = $files !== [] && $this->hasConfig() ? $this->relative($files) : [];
        $level = $this->level($request);
        $command = array_merge(
            Process::phpCommand($binary),
            ['analyse', '--error-format=json', '--no-progress', '--no-interaction', '--no-ansi', '--memory-limit='.$this->memoryLimit()],
            $level === null ? [] : ['--level='.$level],
            $files === [] || $filter !== [] ? [] : array_merge(['--'], $files),
        );
        $timeout = (int) ($this->config['timeout_ms'] ?? 120000);
        $result = Process::run($command, $this->root, null, $timeout);

        if ($result['exit'] === 124) {
            return ProbeResult::error('timeout', 'phpstan timed out after '.$timeout.' ms', $result['duration_ms']);
        }

        $report = json_decode($result['stdout'], true);
        if (! is_array($report) || ! isset($report['totals'])) {
            $detail = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);

            return ProbeResult::error(
                'phpstan',
                'phpstan exited '.$result['exit'].($detail === '' ? '' : ': '.Process::excerpt($detail)),
                $result['duration_ms'],
            );
        }

        $all = $this->failures($report);
        $failures = $filter === [] ? $all : $this->within($all, $filter);
        $value = ['scope' => $scope, 'errors' => count($failures)]
            + ($filter === [] ? [] : ['total' => count($all)])
            + ($files === [] ? [] : ['files' => count($files)])
            + ($level === null ? [] : ['level' => $level]);

        return new ProbeResult(
            status: $failures === [] ? 'passed' : 'failed',
            durationMs: Stats::elapsedMs($started),
            failures: $failures,
            value: $value,
            hasValue: true,
        );
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<Failure>
     */
    private function failures(array $report): array
    {
        $failures = [];
        $files = is_array($report['files'] ?? null) ? $report['files'] : [];
        foreach ($files as $file => $info) {
            $relative = Paths::relative($this->root, (string) $file);
            $messages = is_array($info['messages'] ?? null) ? $info['messages'] : [];
            foreach ($messages as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $line = isset($row['line']) && is_numeric($row['line']) ? (int) $row['line'] : null;
                $text = trim((string) ($row['message'] ?? 'error'));
                $failures[] = new Failure(
                    id: (string) (count($failures) + 1),
                    message: $text.' at '.$relative.($line === null ? '' : ':'.$line),
                    type: 'static',
                    file: $relative,
                    line: $line,
                );
            }
        }

        $general = is_array($report['errors'] ?? null) ? $report['errors'] : [];
        foreach ($general as $text) {
            if (is_string($text) && trim($text) !== '') {
                $failures[] = new Failure(id: (string) (count($failures) + 1), message: trim($text), type: 'static');
            }
        }

        return $failures;
    }

    /**
     * @param  list<string>  $files  absolute
     * @return array<string, true> keyed by root-relative path
     */
    private function relative(array $files): array
    {
        $keys = [];
        foreach ($files as $file) {
            $keys[Paths::relative($this->root, $file)] = true;
        }

        return $keys;
    }

    /**
     * The failures inside the given files, renumbered so get_failures ids match
     * the list. A report-level error without a file is kept.
     *
     * @param  list<Failure>  $failures
     * @param  array<string, true>  $files
     * @return list<Failure>
     */
    private function within(array $failures, array $files): array
    {
        $kept = [];
        foreach ($failures as $failure) {
            if ($failure->file !== null && ! isset($files[$failure->file])) {
                continue;
            }

            $kept[] = new Failure(
                id: (string) (count($kept) + 1),
                message: $failure->message,
                type: $failure->type,
                file: $failure->file,
                line: $failure->line,
            );
        }

        return $kept;
    }

    private function binary(): ?string
    {
        $configured = $this->config['binary'] ?? null;
        $configured = is_string($configured) && $configured !== '' ? $configured : 'vendor/bin/phpstan';
        if (str_contains($configured, '/')) {
            $path = str_starts_with($configured, '/') ? $configured : $this->root.'/'.$configured;

            return is_file($path) ? $path : null;
        }

        return Process::which($configured);
    }

    private function hasConfig(): bool
    {
        foreach (self::NEON as $neon) {
            if (is_file($this->root.'/'.$neon)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The request's level, else the configured one when the project has no
     * phpstan.neon to say so itself.
     *
     * @param  array<string, mixed>  $request
     */
    private function level(array $request): ?int
    {
        if (isset($request['level']) && is_numeric($request['level'])) {
            return max(0, (int) $request['level']);
        }

        if ($this->hasConfig()) {
            return null;
        }

        $level = $this->config['level'] ?? 5;

        return is_numeric($level) ? max(0, (int) $level) : 5;
    }

    private function memoryLimit(): string
    {
        $limit = $this->config['memory_limit'] ?? null;

        return is_string($limit) && $limit !== '' ? $limit : '2G';
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function existing(array $paths): array
    {
        $root = realpath($this->root) ?: $this->root;
        $files = [];
        foreach ($paths as $path) {
            $absolute = str_starts_with($path, '/') ? $path : $root.'/'.ltrim($path, '/');
            if (is_file($absolute) && str_ends_with($absolute, '.php')) {
                $files[] = realpath($absolute) ?: $absolute;
            }
        }

        return array_values(array_unique($files));
    }
}
