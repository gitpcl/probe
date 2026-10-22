<?php

declare(strict_types=1);

namespace Probe\Selective;

use Closure;
use Probe\Contract\SelectiveRunner;
use Probe\Project;
use Probe\Result\Failure;
use Probe\Result\ProbeResult;
use Probe\Support\ChildEnvironment;
use Probe\Support\Paths;
use Probe\Support\Stats;

/**
 * run_affected: pick tests from the dependency graph, then run Pest or PHPUnit.
 * Shards follow probe.workers and the duration book when there is more than one file.
 */
final class AffectedRunner implements SelectiveRunner
{
    private const UNKNOWN_TEST_MS = 100;

    public function __construct(
        private readonly string $root,
        private readonly DependencyGraph $graph,
        private readonly ShardPlanner $planner,
        private readonly DurationBook $durations,
        private readonly int $workers = 1,
        private readonly ?string $runner = null,
        private readonly int $timeoutMs = 120000,
        private readonly int $fullSuiteUnderMs = 0,
        private readonly string $mode = 'fork',
        private readonly ?Closure $beforeFork = null,
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

        $selection = 'given';
        if (isset($request['tests']) && is_array($request['tests'])) {
            $tests = $this->existing($request['tests']);
        } else {
            ['files' => $tests, 'selection' => $selection] = $this->select($paths);
        }

        if ($tests === []) {
            return ProbeResult::passed(Stats::elapsedMs($started), ['ran' => 0] + $this->selected([], $selection), true);
        }

        return $this->runFiles($tests, $started, $selection);
    }

    /**
     * The graph's pick, or every test file when the whole suite is estimated to run
     * under the configured budget: selecting would then save less than it can hide.
     *
     * @param  list<string>  $paths
     * @return array{files: list<string>, selection: string}
     */
    public function select(array $paths): array
    {
        $affected = ['files' => $this->graph->affectedTests($paths), 'selection' => 'affected'];
        if ($this->fullSuiteUnderMs <= 0) {
            return $affected;
        }

        $all = $this->graph->testFiles();
        if ($all === [] || $this->estimate($all) >= $this->fullSuiteUnderMs) {
            return $affected;
        }

        return ['files' => $all, 'selection' => 'all'];
    }

    /**
     * Known durations plus the median known duration for each file without one.
     *
     * @param  list<string>  $files
     */
    private function estimate(array $files): int
    {
        $known = $this->durations->all();
        $values = array_values($known);
        sort($values);
        $median = $values === [] ? self::UNKNOWN_TEST_MS : $values[intdiv(count($values), 2)];

        $total = 0;
        foreach ($files as $file) {
            $total += $known[$file] ?? $median;
        }

        return $total;
    }

    /**
     * @param  list<string>  $files
     */
    public function runFiles(array $files, ?int $started = null, string $selection = 'given'): ProbeResult
    {
        $started ??= hrtime(true);
        $files = array_values(array_filter($files, 'is_file'));
        if ($files === []) {
            return ProbeResult::passed(Stats::elapsedMs($started), ['ran' => 0] + $this->selected([], $selection), true);
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
                value: $this->selected($files, $selection),
                hasValue: true,
            );
        }

        $shards = $this->planner->plan($files, $this->durations->all(), $this->shardCount(count($files)));
        $forked = $this->mode !== 'process' && ForkedShards::available();
        $reports = $forked
            ? (new ForkedShards($this->root, $this->timeoutMs, $this->beforeFork))->run($binary, $shards)
            : $this->executeProcesses($binary, $shards);

        return $this->merge($reports, $started, $files, $selection, $forked ? 'fork' : 'process');
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
     * @param  list<string>  $files
     * @return list<string>
     */
    private function relative(array $files): array
    {
        return array_map(fn (string $file): string => Paths::relative($this->root, $file), $files);
    }

    /**
     * What ran, as the agent needs it: the file count always, the list only
     * when something chose those files (a full run would repeat the suite).
     *
     * @param  list<string>  $files
     * @return array{selection: string, files: int, selected?: list<string>}
     */
    private function selected(array $files, string $selection): array
    {
        $value = ['selection' => $selection, 'files' => count($files)];
        if ($selection !== 'all') {
            $value['selected'] = $this->relative($files);
        }

        return $value;
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

    /** Configured shard count, or one per CPU capped by the file count. */
    private function shardCount(int $files): int
    {
        return $this->workers > 0 ? $this->workers : max(1, min($files, Project::cpuCount()));
    }

    /**
     * One runner process per shard, in parallel, each with its own JUnit file.
     *
     * @param  list<list<string>>  $shards
     * @return list<array{failures: list<array<string, string>>, times: array<string, int>, tests: int, duration_ms: int, status: string}>
     */
    private function executeProcesses(string $binary, array $shards): array
    {
        $jobs = [];
        foreach ($shards as $files) {
            if ($files === []) {
                continue;
            }

            $junit = tempnam(sys_get_temp_dir(), 'probe-junit-');
            if ($junit === false) {
                $jobs[] = ['report' => ShardReport::error('could not allocate a junit file')];

                continue;
            }

            $process = proc_open(
                array_merge([PHP_BINARY, $binary, '--do-not-cache-result', '--log-junit', $junit], $files),
                [1 => ['file', $junit.'.out', 'w'], 2 => ['file', $junit.'.err', 'w']],
                $pipes,
                $this->root,
                ChildEnvironment::current(),
            );

            if (! is_resource($process)) {
                @unlink($junit);
                $jobs[] = ['report' => ShardReport::error('could not start the test runner')];

                continue;
            }

            $jobs[] = ['process' => $process, 'junit' => $junit, 'files' => $files, 'started' => hrtime(true)];
        }

        $deadline = microtime(true) + ($this->timeoutMs / 1000);
        $reports = [];
        foreach ($jobs as $job) {
            if (isset($job['report'])) {
                $reports[] = $job['report'];

                continue;
            }

            $timedOut = false;
            $status = proc_get_status($job['process']);
            while ($status['running']) {
                if (microtime(true) > $deadline) {
                    proc_terminate($job['process'], 9);
                    $timedOut = true;
                    break;
                }

                usleep(20000);
                $status = proc_get_status($job['process']);
            }

            proc_close($job['process']);
            $reports[] = ShardReport::build(
                $this->root,
                $job['files'],
                $job['junit'],
                $job['junit'].'.err',
                Stats::elapsedMs($job['started']),
                $timedOut,
                $timedOut ? 1 : (int) $status['exitcode'],
            );
        }

        return $reports;
    }

    /**
     * @param  list<array{failures: list<array<string, string>>, times: array<string, int>, tests: int, duration_ms: int, status: string}>  $reports
     * @param  list<string>  $files
     */
    private function merge(array $reports, int $started, array $files, string $selection, string $mode): ProbeResult
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

        // duration_ms is wall time; shards run side by side, so their sum is a separate number.
        return new ProbeResult(
            status: $status,
            durationMs: Stats::elapsedMs($started),
            failures: $failures,
            value: ['ran' => $ran, 'shards' => count($reports), 'shard_ms' => $duration, 'mode' => $mode] + $this->selected($files, $selection),
            hasValue: true,
        );
    }
}
