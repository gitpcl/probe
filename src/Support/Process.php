<?php

declare(strict_types=1);

namespace Probe\Support;

/**
 * Local process helper for optional tools (browsers, provers, PHPUnit).
 * A missing binary is a result, not a crash: callers check the exit code.
 */
final class Process
{
    /**
     * @param  list<string>  $command
     * @return array{exit: int, stdout: string, stderr: string, duration_ms: int}
     */
    public static function run(array $command, ?string $cwd = null, ?string $stdin = null, int $timeoutMs = 30000): array
    {
        $started = hrtime(true);
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $cwd, ChildEnvironment::current());

        if (! is_resource($process)) {
            return [
                'exit' => 127,
                'stdout' => '',
                'stderr' => 'could not start process',
                'duration_ms' => 0,
            ];
        }

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + (max(1, $timeoutMs) / 1000);
        $status = ['running' => true, 'exitcode' => -1];

        while (true) {
            $status = proc_get_status($process);
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            if (! $status['running']) {
                break;
            }

            if (microtime(true) > $deadline) {
                proc_terminate($process);
                usleep(100000);
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, 9);
                }
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return [
                    'exit' => 124,
                    'stdout' => $stdout,
                    'stderr' => trim($stderr.' timed out'),
                    'duration_ms' => Stats::elapsedMs($started),
                ];
            }

            usleep(20000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $exit = $status['exitcode'];

        return [
            'exit' => $exit,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'duration_ms' => Stats::elapsedMs($started),
        ];
    }

    public static function which(string $binary): ?string
    {
        if ($binary === '') {
            return null;
        }

        if (str_contains($binary, '/') || str_contains($binary, '\\')) {
            return is_file($binary) ? $binary : null;
        }

        $path = getenv('PATH');
        if (! is_string($path) || $path === '') {
            return null;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory === '') {
                continue;
            }

            $candidate = $directory.DIRECTORY_SEPARATOR.$binary;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function phpCommand(string $binary): array
    {
        if (str_ends_with($binary, '.php')) {
            return [PHP_BINARY, $binary];
        }

        return [$binary];
    }
}
