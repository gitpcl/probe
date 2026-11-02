<?php

declare(strict_types=1);

namespace Probe\Support;

use Dotenv\Dotenv;
use Illuminate\Support\Env;
use Throwable;

/**
 * The environment handed to processes the worker starts (PHPUnit, git, browsers, provers).
 * Laravel loaded the project's env file into this process with putenv, so a child that
 * inherited the environment would see those values ahead of its own phpunit.xml <env>
 * entries. Captured once after boot: the process environment minus every key the env
 * file set. A key the shell set to a different value is kept.
 */
final class ChildEnvironment
{
    /** @var array<string, string>|null */
    private static ?array $captured = null;

    /** @var list<string> */
    private static array $removed = [];

    public static function capture(string $envFile): void
    {
        $environment = getenv();
        $removed = [];

        foreach (self::parse($envFile) as $key => $value) {
            if (array_key_exists($key, $environment) && $environment[$key] === (string) $value) {
                unset($environment[$key]);
                $removed[] = $key;
            }
        }

        self::$captured = $environment;
        self::$removed = $removed;
    }

    /**
     * Null when nothing was captured: proc_open then inherits, as outside a worker.
     *
     * @return array<string, string>|null
     */
    public static function current(): ?array
    {
        return self::$captured;
    }

    /**
     * The variables Laravel's parallel testing keys on, so each of several shards
     * boots its own test database ({database}_test_probe_<n>) instead of sharing
     * phpunit.xml's. Empty for a single shard, which keeps the configured database.
     *
     * @return array<string, string>
     */
    public static function forShard(int $index, int $count): array
    {
        if ($count < 2) {
            return [];
        }

        return ['TEST_TOKEN' => 'probe_'.($index + 1), 'LARAVEL_PARALLEL_TESTING' => '1'];
    }

    /**
     * The same subtraction for a forked child that keeps running PHP: drop the env file's
     * keys from the live environment and reset Laravel's Dotenv repository, whose record
     * of what it loaded would otherwise let the env file overwrite phpunit.xml values.
     */
    public static function applyInProcess(): void
    {
        foreach (self::$removed as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        if (class_exists(Env::class)) {
            Env::enablePutenv();
        }
    }

    public static function reset(): void
    {
        self::$captured = null;
        self::$removed = [];
    }

    /** @return array<string, string|null> */
    private static function parse(string $envFile): array
    {
        if (! is_file($envFile)) {
            return [];
        }

        try {
            return Dotenv::parse((string) file_get_contents($envFile));
        } catch (Throwable) {
            return [];
        }
    }
}
