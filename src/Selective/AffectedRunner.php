<?php

declare(strict_types=1);

namespace Probe\Selective;

use Probe\Contract\SelectiveRunner;
use Probe\Result\Failure;
use Probe\Result\ProbeResult;
use Probe\Support\Paths;
use Probe\Support\Stats;

/**
 * run_affected: pick tests from the dependency graph, then run Pest or PHPUnit.
 * Shards follow probe.workers and the duration book when there is more than one file.
 */
final class AffectedRunner implements SelectiveRunner
{
    public function __construct(
        private readonly string $root,
        private readonly DependencyGraph $graph,
        private readonly ShardPlanner $planner,
        private readonly DurationBook $durations,
        private readonly int $workers = 1,
        private readonly ?string $runner = null,
        private readonly int $timeoutMs = 120000,
    ) {}

    /** @param  list<string>  $paths */
    public function runAffected(array $paths): array
    {
        return $this->run(['paths' => $paths])->toArray();
    }

    /** @param  array<string, mixed>  $request */
    public function run(array $request): ProbeResult
    {
        $started = hrtime(true);
        $paths = $this->paths($request);

        if ($paths === [] && ($request['paths'] ?? null) === null) {
            return ProbeResult::error('paths', 'run_affected needs paths or a git diff');
        }

        $tests = isset($request['tests']) && is_array($request['tests'])
            ? $this->existing($request['tests'])
            : $this->graph->affectedTests($paths);

        if ($tests === []) {
            return ProbeResult::passed(Stats::elapsedMs($started), ['ran' => 0], true);
        }

        return $this->runFiles($tests, $started);
    }

    /**
     * @param  list<string>  $files
     */
    public function runFiles(array $files, ?int $started = null): ProbeResult
    {
        $started ??= hrtime(true);
        $files = array_values(array_filter($files, 'is_file'));
        if ($files === []) {
            return ProbeResult::passed(Stats::elapsedMs($started), ['ran' => 0], true);
        }

        $binary = $this->resolveRunner();
        if ($binary === null) {
            return new ProbeResult(
                status: 'error',
                durationMs: Stats::elapsedMs($started),
                failures: [new Failure(
                    id: 'missing_tool',
                    message: 'pest or phpunit was not found',
                )],
                value: ['selected' => array_map(fn (string $file): string => Paths::relative($this->root, $file), $files)],
                hasValue: true,
            );
        }

        $shards = $this->planner->plan($files, $this->durations->all(), $this->workers);
        $reports = count($shards) <= 1
            ? [$this->executeShard($binary, $shards[0] ?? [])]
            : $this->executeParallel($binary, $shards);

        return $this->merge($reports, $started);
    }

    public function resolveRunner(): ?string
    {
        if (is_string($this->runner) && $this->runner !== '' && is_file($this->runner)) {
            return $this->runner;
        }

        foreach (['pest', 'phpunit'] as $name) {
            $path = $this->root.'/vendor/bin/'.$name;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return list<string>
     */
    private function paths(array $request): array
    {
        $paths = $request['paths'] ?? null;
        if (is_string($paths) && $paths !== '') {
            $paths = array_map('trim', explode(',', $paths));
        }

        if (! is_array($paths) || $paths === []) {
            return GitChanges::names($this->root);
        }

        $clean = [];
        foreach ($paths as $path) {
            if (is_string($path) && trim($path) !== '') {
                $clean[] = trim($path);
            }
        }

        return $clean;
    }

    /**
     * @param  list<mixed>  $tests
     * @return list<string>
     */
    private function existing(array $tests): array
    {
        $files = [];
        foreach ($tests as $test) {
            if (! is_string($test) || $test === '') {
                continue;
            }

            $resolved = Paths::resolve($this->root, $test) ?? (is_file($test) ? $test : null);
            if ($resolved !== null) {
                $files[] = $resolved;
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * @param  list<string>  $files
     * @return array{failures: list<array<string, string>>, times: array<string, int>, tests: int, duration_ms: int, status: string}
     */
    private function executeShard(string $binary, array $files): array
    {
        if ($files === []) {
            return ['failures' => [], 'times' => [], 'tests' => 0, 'duration_ms' => 0, 'status' => 'passed'];
        }

        $junit = tempnam(sys_get_temp_dir(), 'probe-junit-');
        if ($junit === false) {
            return [
                'failures' => [['id' => '1', 'message' => 'could not allocate a junit file', 'type' => 'test', 'file' => '']],
                'times' => [],
                'tests' => 0,
                'duration_ms' => 0,
                'status' => 'error',
            ];
        }

        $stdout = $junit.'.out';
        $stderr = $junit.'.err';
        $command = array_merge(
            [PHP_BINARY, $binary, '--do-not-cache-result', '--log-junit', $junit],
            $files,
        );

        $started = hrtime(true);
        $process = proc_open($command, [
            1 => ['file', $stdout, 'w'],
            2 => ['file', $stderr, 'w'],
        ], $pipes, $this->root);

        if (! is_resource($process)) {
            @unlink($junit);

            return [
                'failures' => [['id' => '1', 'message' => 'could not start the test runner', 'type' => 'test', 'file' => '']],
                'times' => [],
                'tests' => 0,
                'duration_ms' => 0,
                'status' => 'error',
            ];
        }

        $deadline = microtime(true) + ($this->timeoutMs / 1000);
        $timedOut = false;
        while (proc_get_status($process)['running']) {
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                $timedOut = true;
                break;
            }
            usleep(20000);
        }

        $status = proc_get_status($process);
        proc_close($process);
        $duration = Stats::elapsedMs($started);
        $parsed = JunitReport::parse((string) @file_get_contents($junit));
        $errorText = trim((string) @file_get_contents($stderr));
        @unlink($junit);
        @unlink($stdout);
        @unlink($stderr);

        if ($parsed['times'] === []) {
            $share = (int) max(1, $duration / max(1, count($files)));
            foreach ($files as $file) {
                $parsed['times'][$file] = $share;
            }
        }

        if ($timedOut) {
            $parsed['failures'][] = [
                'id' => '1',
                'message' => 'test shard timed out',
                'type' => 'test',
                'file' => Paths::relative($this->root, $files[0]),
            ];
            $parsed['status'] = 'error';
        } elseif ($parsed['failures'] !== []) {
            $parsed['status'] = 'failed';
        } elseif ($status['exitcode'] !== 0 && $parsed['tests'] === 0) {
            $parsed['failures'][] = [
                'id' => '1',
                'message' => $errorText !== '' ? $errorText : 'test runner exited '.$status['exitcode'],
                'type' => 'test',
                'file' => Paths::relative($this->root, $files[0]),
            ];
            $parsed['status'] = 'error';
        } else {
            $parsed['status'] = 'passed';
        }

        $parsed['duration_ms'] = $duration;

        return $parsed;
    }

    /**
     * @param  list<list<string>>  $shards
     * @return list<array{failures: list<array<string, string>>, times: array<string, int>, tests: int, duration_ms: int, status: string}>
     */
    private function executeParallel(string $binary, array $shards): array
    {
        $jobs = [];
        foreach ($shards as $files) {
            if ($files === []) {
                continue;
            }

            $junit = tempnam(sys_get_temp_dir(), 'probe-junit-');
            if ($junit === false) {
                continue;
            }

            $command = array_merge(
                [PHP_BINARY, $binary, '--do-not-cache-result', '--log-junit', $junit],
                $files,
            );
            $stdout = $junit.'.out';
            $stderr = $junit.'.err';
            $process = proc_open($command, [
                1 => ['file', $stdout, 'w'],
                2 => ['file', $stderr, 'w'],
            ], $pipes, $this->root);

            if (! is_resource($process)) {
                @unlink($junit);

                continue;
            }

            $jobs[] = [
                'process' => $process,
                'junit' => $junit,
                'stdout' => $stdout,
                'stderr' => $stderr,
                'files' => $files,
                'started' => hrtime(true),
            ];
        }

        $reports = [];
        foreach ($jobs as $job) {
            $deadline = microtime(true) + ($this->timeoutMs / 1000);
            $timedOut = false;
            while (proc_get_status($job['process'])['running']) {
                if (microtime(true) > $deadline) {
                    proc_terminate($job['process'], 9);
                    $timedOut = true;
                    break;
                }
                usleep(20000);
            }

            $status = proc_get_status($job['process']);
            proc_close($job['process']);
            $duration = Stats::elapsedMs($job['started']);
            $parsed = JunitReport::parse((string) @file_get_contents($job['junit']));
            $errorText = trim((string) @file_get_contents($job['stderr']));
            @unlink($job['junit']);
            @unlink($job['stdout']);
            @unlink($job['stderr']);

            if ($parsed['times'] === []) {
                $share = (int) max(1, $duration / max(1, count($job['files'])));
                foreach ($job['files'] as $file) {
                    $parsed['times'][$file] = $share;
                }
            }

            if ($timedOut) {
                $parsed['failures'][] = [
                    'id' => '1',
                    'message' => 'test shard timed out',
                    'type' => 'test',
                    'file' => Paths::relative($this->root, $job['files'][0]),
                ];
                $parsed['status'] = 'error';
            } elseif ($parsed['failures'] !== []) {
                $parsed['status'] = 'failed';
            } elseif ($status['exitcode'] !== 0 && $parsed['tests'] === 0) {
                $parsed['failures'][] = [
                    'id' => '1',
                    'message' => $errorText !== '' ? $errorText : 'test runner exited '.$status['exitcode'],
                    'type' => 'test',
                    'file' => Paths::relative($this->root, $job['files'][0]),
                ];
                $parsed['status'] = 'error';
            } else {
                $parsed['status'] = 'passed';
            }

            $parsed['duration_ms'] = $duration;
            $reports[] = $parsed;
        }

        return $reports;
    }

    /**
     * @param  list<array{failures: list<array<string, string>>, times: array<string, int>, tests: int, duration_ms: int, status: string}>  $reports
     */
    private function merge(array $reports, int $started): ProbeResult
    {
        $status = 'passed';
        $failures = [];
        $times = [];
        $ran = 0;
        $duration = 0;

        foreach ($reports as $report) {
            $duration += $report['duration_ms'];
            $ran += $report['tests'];
            $times = array_merge($times, $report['times']);
            if ($report['status'] === 'error') {
                $status = 'error';
            } elseif ($report['status'] === 'failed' && $status !== 'error') {
                $status = 'failed';
            }

            foreach ($report['failures'] as $failure) {
                $failures[] = new Failure(
                    id: (string) (count($failures) + 1),
                    message: $failure['message'],
                    type: $failure['type'] !== '' ? $failure['type'] : 'test',
                    file: $failure['file'] !== '' ? $failure['file'] : null,
                );
            }
        }

        $this->durations->record($times);

        return new ProbeResult(
            status: $status,
            durationMs: max($duration, Stats::elapsedMs($started)),
            failures: $failures,
            value: ['ran' => $ran, 'shards' => count($reports)],
            hasValue: true,
        );
    }
}
