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
}
