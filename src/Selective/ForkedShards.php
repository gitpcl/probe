<?php

declare(strict_types=1);

namespace Probe\Selective;

use Probe\Support\ChildEnvironment;
use Probe\Support\Stats;
use Throwable;

/**
 * Runs test shards as forked children of the warm worker. A child inherits Composer,
 * the compiled framework and OPcache for free, drops the worker's env file values,
 * and includes the Pest or PHPUnit binary in-process with --no-output; JUnit carries
 * the results back. No PHP startup, no Composer, no bootstrap per shard.
 */
final class ForkedShards
{
    /** @var callable|null */
    private $beforeFork;

    public function __construct(
        private readonly string $root,
        private readonly int $timeoutMs,
        ?callable $beforeFork = null,
    ) {
        $this->beforeFork = $beforeFork;
    }

    public static function available(): bool
    {
        return function_exists('pcntl_fork') && function_exists('pcntl_waitpid');
    }

    /**
     * @param  list<list<string>>  $shards
     * @return list<array{failures: list<array<string, string>>, times: array<string, int>, tests: int, duration_ms: int, status: string}>
     */
    public function run(string $binary, array $shards): array
    {
        if ($this->beforeFork !== null) {
            ($this->beforeFork)();
        }

        $jobs = [];
        $live = array_values(array_filter($shards, static fn (array $files): bool => $files !== []));
        $count = count($live);
        foreach ($live as $index => $files) {
            $junit = tempnam(sys_get_temp_dir(), 'probe-junit-');
            if ($junit === false) {
                continue;
            }

            $pid = pcntl_fork();
            if ($pid === -1) {
                @unlink($junit);

                continue;
            }

            if ($pid === 0) {
                $this->child($binary, $files, $junit, ChildEnvironment::forShard($index, $count));
            }

            $jobs[] = ['pid' => $pid, 'junit' => $junit, 'files' => $files, 'started' => hrtime(true)];
        }

        $deadline = microtime(true) + ($this->timeoutMs / 1000);
        $reports = [];
        foreach ($jobs as $job) {
            [$finished, $exitCode] = $this->wait($job['pid'], $deadline);
            $reports[] = ShardReport::build(
                $this->root,
                $job['files'],
                $job['junit'],
                $job['junit'].'.err',
                Stats::elapsedMs($job['started']),
                ! $finished,
                $exitCode,
            );
        }

        return $reports;
    }

    /**
     * @param  list<string>  $files
     * @param  array<string, string>  $environment
     */
    private function child(string $binary, array $files, string $junit, array $environment): never
    {
        chdir($this->root);
        ChildEnvironment::applyInProcess();
        foreach ($environment as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);
            pcntl_signal(SIGALRM, SIG_DFL);
        }

        // A fatal inside the runner leaves no JUnit; keep its message for the report.
        register_shutdown_function(static function () use ($junit): void {
            $last = error_get_last();
            if ($last !== null && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                file_put_contents($junit.'.err', $last['message'].' at '.$last['file'].':'.$last['line']);
            }
        });

        $argv = array_merge([$binary, '--no-output', '--do-not-cache-result', '--log-junit', $junit], $files);
        $argc = count($argv);
        $_SERVER['argv'] = $argv;
        $_SERVER['argc'] = $argc;

        try {
            include $binary;
        } catch (Throwable $exception) {
            file_put_contents($junit.'.err', $exception->getMessage());
            exit(1);
        }

        exit(0);
    }

    /**
     * @return array{0: bool, 1: int} finished before the deadline, exit code
     */
    private function wait(int $pid, float $deadline): array
    {
        while (true) {
            $status = 0;
            $result = pcntl_waitpid($pid, $status, WNOHANG);
            if ($result === $pid || $result === -1) {
                return [true, pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 1];
            }

            if (microtime(true) > $deadline) {
                if (function_exists('posix_kill')) {
                    posix_kill($pid, SIGKILL);
                }
                pcntl_waitpid($pid, $status);

                return [false, 1];
            }

            usleep(5000);
        }
    }
}
