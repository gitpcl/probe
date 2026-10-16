<?php

declare(strict_types=1);

namespace Probe\Support;

/**
 * Reads a few Probe keys from .env without booting Laravel.
 * The thin client uses this so a warm probe does not pay for framework boot.
 */
final class EnvFile
{
    /** @var array<string, array<string, string>> */
    private static array $cache = [];

    public static function get(string $root, string $key): ?string
    {
        $fromProcess = getenv($key);
        if (is_string($fromProcess) && $fromProcess !== '') {
            return $fromProcess;
        }

        $value = self::load($root)[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return $value;
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    /** @return array<string, string> */
    private static function load(string $root): array
    {
        $path = rtrim($root, '/').'/.env';
        if (isset(self::$cache[$path])) {
            return self::$cache[$path];
        }

        self::$cache[$path] = [];
        if (! is_file($path)) {
            return self::$cache[$path];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return self::$cache[$path];
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            if (! str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            if (! str_starts_with($name, 'PROBE_')) {
                continue;
            }

            self::$cache[$path][$name] = self::unquote(trim($value));
        }

        return self::$cache[$path];
    }

    private static function unquote(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $quote = $value[0];
        if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote) && strlen($value) >= 2) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
