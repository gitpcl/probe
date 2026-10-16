<?php

declare(strict_types=1);

namespace Probe\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Compiles Probe's own classes into OPcache when a worker boots.
 * Long-lived workers keep the bytecode. File-cache settings on the
 * supervisor make the next cold start reuse it.
 */
final class Preloader
{
    /**
     * @param  list<string>  $extra
     * @return array{opcache: bool, compiled: int}
     */
    public function warm(array $extra = []): array
    {
        if (! function_exists('opcache_compile_file')) {
            return ['opcache' => false, 'compiled' => 0];
        }

        $compiled = 0;
        foreach ($this->files($extra) as $file) {
            if (is_file($file) && @opcache_compile_file($file)) {
                $compiled++;
            }
        }

        return ['opcache' => true, 'compiled' => $compiled];
    }

    /**
     * @param  list<string>  $extra
     * @return list<string>
     */
    public function files(array $extra = []): array
    {
        $root = dirname(__DIR__);
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        foreach ($extra as $file) {
            if ($file !== '') {
                $files[] = $file;
            }
        }

        sort($files);

        return $files;
    }
}
