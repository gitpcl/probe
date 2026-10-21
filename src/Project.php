<?php

declare(strict_types=1);

namespace Probe;

use Illuminate\Container\Container;
use Probe\Support\EnvFile;
use RuntimeException;
use Throwable;

final class Project
{
    public static function find(?string $start = null): string
    {
        $dir = $start ?? getcwd();
        if ($dir === false || $dir === '') {
            throw new RuntimeException('Probe could not determine the working directory.');
        }

        $dir = realpath($dir) ?: $dir;

        while ($dir !== dirname($dir)) {
            if (is_file($dir.'/artisan') && is_file($dir.'/composer.json')) {
                return $dir;
            }

            $dir = dirname($dir);
        }

        $cwd = getcwd();

        throw new RuntimeException('Probe could not find a Laravel application (artisan and composer.json) from '.($cwd === false ? $start ?? '' : $cwd).'.');
    }

    public static function home(string $root): string
    {
        if (self::configForRoot($root)) {
            $configured = config('probe.home');
            if (is_string($configured) && $configured !== '') {
                return $configured;
            }
        }

        $fromEnv = EnvFile::get($root, 'PROBE_HOME');
        if ($fromEnv !== null) {
            return $fromEnv;
        }

        return $root.'/storage/framework/probe';
    }

    public static function socket(string $root, int $index = 0): string
    {
        if ($index === 0) {
            if (self::configForRoot($root)) {
                $configured = config('probe.socket');
                if (is_string($configured) && $configured !== '') {
                    return self::fitUnixPath($configured);
                }
            }

            $fromEnv = EnvFile::get($root, 'PROBE_SOCKET');
            if ($fromEnv !== null) {
                return self::fitUnixPath($fromEnv);
            }
        }

        $suffix = $index === 0 ? '/worker.sock' : '/worker-'.$index.'.sock';

        return self::fitUnixPath(self::home($root).$suffix);
    }

    public static function activeFile(string $root): string
    {
        return self::home($root).'/active.json';
    }

    public static function log(string $root): string
    {
        return $root.'/storage/logs/probe-worker.log';
    }

    public static function workerCount(string $root): int
    {
        if (self::configForRoot($root)) {
            return max(1, (int) config('probe.workers', 1));
        }

        $fromEnv = EnvFile::get($root, 'PROBE_WORKERS');

        return max(1, $fromEnv !== null ? (int) $fromEnv : 1);
    }

    /**
     * The longest a tool may run inside the worker. The client waits at least this
     * long so a slow browser, test run or prover is not mistaken for a dead worker.
     */
    public static function toolTimeoutMs(string $root, string $tool): int
    {
        [$env, $key, $default] = match ($tool) {
            'probe_browser' => ['PROBE_BROWSER_TIMEOUT_MS', 'probe.browser.timeout_ms', 8000],
            'run_affected', 'rerun_failed', 'mutate_check', 'architecture_check', 'auto_probe' => ['PROBE_SELECTIVE_TIMEOUT_MS', 'probe.selective.timeout_ms', 120000],
            'formal_check' => ['PROBE_FORMAL_TIMEOUT_MS', 'probe.formal.timeout_ms', 20000],
            default => [null, null, 0],
        };

        if ($env === null || $key === null) {
            return 0;
        }

        if (self::configForRoot($root)) {
            return max(0, (int) config($key, $default));
        }

        $fromEnv = EnvFile::get($root, $env);

        return max(0, $fromEnv !== null ? (int) $fromEnv : $default);
    }

    public static function timeoutMs(string $root): int
    {
        if (self::configForRoot($root)) {
            return max(1, (int) config('probe.timeout_ms', 5000));
        }

        $fromEnv = EnvFile::get($root, 'PROBE_TIMEOUT_MS');

        return max(1, $fromEnv !== null ? (int) $fromEnv : 5000);
    }

    public static function bootTimeoutSeconds(string $root): int
    {
        if (self::configForRoot($root)) {
            return max(5, (int) config('probe.boot_timeout_seconds', 60));
        }

        $fromEnv = EnvFile::get($root, 'PROBE_BOOT_TIMEOUT');

        return max(5, $fromEnv !== null ? (int) $fromEnv : 60);
    }

    public static function opcacheEnabled(string $root): bool
    {
        if (self::configForRoot($root)) {
            return (bool) config('probe.opcache', true);
        }

        $fromEnv = EnvFile::get($root, 'PROBE_OPCACHE');
        if ($fromEnv === null) {
            return true;
        }

        return filter_var($fromEnv, FILTER_VALIDATE_BOOL);
    }

    public static function phpBinary(string $root): string
    {
        $binary = EnvFile::get($root, 'PROBE_PHP');

        return $binary ?? PHP_BINARY;
    }

    public static function configBound(): bool
    {
        if (! class_exists(Container::class)) {
            return false;
        }

        try {
            return Container::getInstance()->bound('config');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Runtime config belongs to the booted application only when that
     * application is the project being probed. A thin client, or a test
     * process that booted a different app, keeps the root-based defaults.
     */
    private static function configForRoot(string $root): bool
    {
        if (! self::configBound() || ! function_exists('base_path')) {
            return false;
        }

        try {
            $base = base_path();
        } catch (Throwable) {
            return false;
        }

        $baseReal = realpath($base) ?: $base;
        $rootReal = realpath($root) ?: $root;

        return $baseReal === $rootReal;
    }

    /**
     * Unix domain socket paths are limited to about 108 bytes.
     */
    private static function fitUnixPath(string $path): string
    {
        if (strlen($path) <= 100) {
            return $path;
        }

        return sys_get_temp_dir().'/probe-'.substr(hash('sha256', $path), 0, 20).'.sock';
    }
}
