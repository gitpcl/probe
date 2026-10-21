<?php

declare(strict_types=1);

namespace Probe\Support;

use Dotenv\Dotenv;
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

    public static function capture(string $envFile): void
    {
        $environment = getenv();

        foreach (self::parse($envFile) as $key => $value) {
            if (array_key_exists($key, $environment) && $environment[$key] === (string) $value) {
                unset($environment[$key]);
            }
        }

        self::$captured = $environment;
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

    public static function reset(): void
    {
        self::$captured = null;
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
