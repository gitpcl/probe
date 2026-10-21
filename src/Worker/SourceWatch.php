<?php

declare(strict_types=1);

namespace Probe\Worker;

use Probe\Support\Paths;

/**
 * Tells a warm worker when the code it loaded is no longer the code on disk.
 * Each check stats the included files outside vendor (plus .env and composer.lock),
 * remembers the first mtime it saw, and names the first file that moved since.
 * A file first seen with an mtime newer than the previous check counts as moved:
 * it may have been edited between its include and this check. Vendor changes
 * are a composer step; those recycle through --cold.
 */
final class SourceWatch
{
    private readonly string $root;

    private readonly string $vendor;

    /** @var array<string, int> */
    private array $seen = [];

    private int $lastScan = 0;

    public function __construct(string $root)
    {
        $this->root = rtrim(realpath($root) ?: $root, '/');
        $this->vendor = $this->root.'/vendor/';
        $this->scan(false);
    }

    /** The first changed file relative to the root, or null when the loaded code is current. */
    public function changed(): ?string
    {
        return $this->scan(true);
    }

    private function scan(bool $report): ?string
    {
        clearstatcache();
        $previous = $this->lastScan;
        $this->lastScan = time();
        $changed = null;

        foreach ($this->candidates() as $file) {
            $mtime = @filemtime($file);
            $seen = $this->seen[$file] ?? null;

            if ($seen === null) {
                if ($mtime === false) {
                    continue;
                }

                $this->seen[$file] = $mtime;
                if ($report && $mtime >= $previous) {
                    $changed ??= $file;
                }

                continue;
            }

            if ($mtime !== $seen) {
                $changed ??= $file;
            }
        }

        return $changed === null ? null : Paths::relative($this->root, $changed);
    }

    /** @return list<string> */
    private function candidates(): array
    {
        $files = [];
        foreach (get_included_files() as $file) {
            if (! str_starts_with($file, $this->vendor)) {
                $files[] = $file;
            }
        }

        $files[] = $this->root.'/.env';
        $files[] = $this->root.'/composer.lock';

        return $files;
    }
}
