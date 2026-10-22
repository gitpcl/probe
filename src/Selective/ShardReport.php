<?php

declare(strict_types=1);

namespace Probe\Selective;

use Probe\Support\Paths;

/**
 * Turns one shard's JUnit file, exit and error text into the report shape the
 * runner merges, whether the shard ran as a process or as a forked child.
 */
final class ShardReport
{
    /**
     * @param  list<string>  $files
     * @return array{failures: list<array<string, string>>, times: array<string, int>, tests: int, duration_ms: int, status: string}
     */
    public static function build(string $root, array $files, string $junit, string $errorFile, int $durationMs, bool $timedOut, int $exitCode): array
    {
        $parsed = JunitReport::parse((string) @file_get_contents($junit), $root);
        $errorText = trim((string) @file_get_contents($errorFile));
        @unlink($junit);
        @unlink($junit.'.out');
        @unlink($errorFile);

        if ($parsed['times'] === []) {
            $share = (int) max(1, $durationMs / max(1, count($files)));
            foreach ($files as $file) {
                $parsed['times'][$file] = $share;
            }
        }

        $first = Paths::relative($root, $files[0] ?? '');
        if ($timedOut) {
            $parsed['failures'][] = ['id' => '1', 'message' => 'test shard timed out', 'type' => 'test', 'file' => $first];
            $parsed['status'] = 'error';
        } elseif ($parsed['failures'] !== []) {
            $parsed['status'] = 'failed';
        } elseif ($exitCode !== 0 && $parsed['tests'] === 0) {
            $parsed['failures'][] = [
                'id' => '1',
                'message' => $errorText !== '' ? $errorText : 'test runner exited '.$exitCode,
                'type' => 'test',
                'file' => $first,
            ];
            $parsed['status'] = 'error';
        } else {
            $parsed['status'] = 'passed';
        }

        $parsed['duration_ms'] = $durationMs;

        return $parsed;
    }

    /**
     * @return array{failures: list<array<string, string>>, times: array<string, int>, tests: int, duration_ms: int, status: string}
     */
    public static function error(string $message): array
    {
        return [
            'failures' => [['id' => '1', 'message' => $message, 'type' => 'test', 'file' => '']],
            'times' => [],
            'tests' => 0,
            'duration_ms' => 0,
            'status' => 'error',
        ];
    }
}
