<?php

declare(strict_types=1);

namespace Probe\Runner;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Points the worker's default connection at SQLite :memory: when the
 * host app allows it, then rolls each probe's writes back.
 */
final class DatabaseGuard
{
    private ?Application $app = null;

    private string $mode = 'host';

    private ?string $notice = null;

    private ?string $migrateError = null;

    public function boot(Application $app): string
    {
        $this->app = $app;
        $config = $app->make('config');
        $requested = (string) $config->get('probe.database', 'sqlite_memory');

        if ($requested !== 'sqlite_memory') {
            $this->mode = 'host';

            return $this->mode;
        }

        if (! extension_loaded('pdo_sqlite')) {
            $this->mode = 'host';
            $this->notice = 'pdo_sqlite is not loaded, so probes use the host database';

            return $this->mode;
        }

        $original = $config->get('database.default');
        $config->set('database.connections.probe', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $config->set('database.default', 'probe');

        $database = $app->make('db');
        if (is_string($original) && $original !== '' && $original !== 'probe') {
            $database->purge($original);
        }
        $database->purge('probe');
        $database->connection('probe');

        if ((bool) $config->get('probe.migrate', true)) {
            try {
                $app->make(Kernel::class)->call('migrate', [
                    '--force' => true,
                    '--database' => 'probe',
                    '--no-interaction' => true,
                ]);
            } catch (Throwable $exception) {
                $this->migrateError = $exception->getMessage();
            }
        }

        $this->mode = 'sqlite_memory';

        return $this->mode;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function notice(): ?string
    {
        return $this->notice;
    }

    public function migrateError(): ?string
    {
        return $this->migrateError;
    }

    public function isolate(callable $callback): mixed
    {
        if (! $this->shouldIsolate()) {
            return $callback();
        }

        try {
            $connection = $this->app->make('db')->connection();
            $connection->beginTransaction();
        } catch (Throwable) {
            return $callback();
        }

        try {
            $result = $callback();
            $this->rollBack($connection);

            return $result;
        } catch (Throwable $exception) {
            $this->rollBack($connection);

            throw $exception;
        }
    }

    /**
     * Before a fork: drop every host connection so the child opens its own instead
     * of sharing the parent's socket. The in-memory SQLite connection stays: each
     * process gets its own copy of that database.
     */
    public function releaseConnections(): void
    {
        if ($this->app === null || ! $this->app->bound('db')) {
            return;
        }

        try {
            $manager = $this->app->make('db');
            foreach (array_keys($manager->getConnections()) as $name) {
                if ($this->mode === 'sqlite_memory' && $name === 'probe') {
                    continue;
                }

                $manager->purge($name);
            }
        } catch (Throwable) {
            // A connection that cannot be dropped is reported by the probe that uses it.
        }
    }

    private function shouldIsolate(): bool
    {
        if ($this->app === null || ! $this->app->bound('config')) {
            return false;
        }

        return (bool) $this->app->make('config')->get('probe.isolate', true);
    }

    private function rollBack(object $connection): void
    {
        try {
            if (method_exists($connection, 'transactionLevel') && $connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        } catch (Throwable) {
            // The probe result is the caller's concern. A failed rollback
            // leaves the worker to be recycled on the next timeout or stop.
        }
    }
}
