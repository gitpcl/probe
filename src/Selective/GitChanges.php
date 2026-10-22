<?php

declare(strict_types=1);

namespace Probe\Selective;

use Probe\Support\Process;

final class GitChanges
{
    /** @return list<string> */
    public static function names(string $root): array
    {
        if (! is_dir($root.'/.git')) {
            return [];
        }

        $names = [];
        $commands = [
            ['git', '-C', $root, 'diff', '--name-only', 'HEAD'],
            ['git', '-C', $root, 'ls-files', '--others', '--exclude-standard'],
        ];

        foreach ($commands as $command) {
            $result = Process::run($command, $root, null, 5000);
            if ($result['exit'] !== 0) {
                continue;
            }

            foreach (preg_split('/\R/', $result['stdout']) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, 'vendor/') || str_starts_with($line, 'node_modules/')) {
                    continue;
                }

                $names[] = $line;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * The paths a request names (array or comma list), or the git diff when it names none.
     *
     * @return list<string>
     */
    public static function requested(string $root, mixed $paths): array
    {
        if (is_string($paths) && $paths !== '') {
            $paths = array_map('trim', explode(',', $paths));
        }

        if (! is_array($paths) || $paths === []) {
            return self::names($root);
        }

        $clean = [];
        foreach ($paths as $path) {
            if (is_string($path) && trim($path) !== '') {
                $clean[] = trim($path);
            }
        }

        return $clean;
    }
}
