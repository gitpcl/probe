<?php

declare(strict_types=1);

namespace Probe\Support;

final class Paths
{
    public static function resolve(string $root, string $path): ?string
    {
        $rootReal = realpath($root);
        if ($rootReal === false) {
            return null;
        }

        $absolute = str_starts_with($path, '/') ? $path : $rootReal.'/'.$path;
        $real = realpath($absolute);
        if ($real === false) {
            return null;
        }

        if ($real !== $rootReal && ! str_starts_with($real, $rootReal.'/')) {
            return null;
        }

        return $real;
    }

    public static function relative(string $root, string $path): string
    {
        $rootReal = realpath($root) ?: $root;
        $prefix = rtrim($rootReal, '/').'/';

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }
}
