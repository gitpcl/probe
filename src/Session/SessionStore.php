<?php

declare(strict_types=1);

namespace Probe\Session;

use Throwable;

/**
 * Last probe, shared across workers so get_failures and rerun_failed
 * see the newest run. The file is the progressive-detail hook.
 */
final class SessionStore
{
    public function __construct(private readonly string $path) {}

    /** @return array<string, mixed> */
    public function get(): array
    {
        $session = $this->withLock(function (): array {
            if (! is_file($this->path)) {
                return $this->empty();
            }

            $raw = file_get_contents($this->path);
            if ($raw === false || trim($raw) === '') {
                return $this->empty();
            }

            try {
                $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                return $this->empty();
            }

            return is_array($data) ? $data : $this->empty();
        });

        return is_array($session) ? $session : $this->empty();
    }

    /** @param  array<string, mixed>  $session */
    public function put(array $session): void
    {
        $this->withLock(function () use ($session): void {
            $directory = dirname($this->path);
            if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                return;
            }

            $session['updated_at'] = time();
            file_put_contents(
                $this->path,
                json_encode($session, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            );
        });
    }

    private function withLock(callable $callback): mixed
    {
        $directory = dirname($this->path);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return $callback();
        }

        $lock = fopen($this->path.'.lock', 'c');
        if ($lock === false) {
            return $callback();
        }

        try {
            flock($lock, LOCK_EX);

            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string, mixed> */
    private function empty(): array
    {
        return [
            'code' => null,
            'status' => 'passed',
            'duration_ms' => 0,
            'failures' => [],
            'has_value' => false,
            'value' => null,
        ];
    }
}
