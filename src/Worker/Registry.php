<?php

declare(strict_types=1);

namespace Probe\Worker;

use Throwable;

/**
 * active.json tells the thin client which warm workers are listening
 * without booting Laravel.
 */
final class Registry
{
    /**
     * @param  array<string, mixed>  $worker
     */
    public function publish(string $activeFile, array $worker): void
    {
        $this->update($activeFile, function (array $state) use ($worker): array {
            $state['workers'][(string) $worker['index']] = $worker;

            return $state;
        });
    }

    public function unpublish(string $activeFile, int $index): void
    {
        if (! is_file($activeFile)) {
            return;
        }

        $this->update($activeFile, function (array $state) use ($index): array {
            unset($state['workers'][(string) $index]);

            return $state;
        });
    }

    /** @return array<string, mixed> */
    public function read(string $activeFile): array
    {
        if (! is_file($activeFile)) {
            return ['workers' => []];
        }

        try {
            $data = json_decode((string) file_get_contents($activeFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return ['workers' => []];
        }

        if (! is_array($data)) {
            return ['workers' => []];
        }

        if (! isset($data['workers']) || ! is_array($data['workers'])) {
            $data['workers'] = [];
        }

        return $data;
    }

    private function update(string $activeFile, callable $callback): void
    {
        $directory = dirname($activeFile);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return;
        }

        $lock = fopen($activeFile.'.lock', 'c');
        if ($lock === false) {
            return;
        }

        try {
            flock($lock, LOCK_EX);
            $state = $this->read($activeFile);
            $state = $callback($state);
            if (! isset($state['workers']) || ! is_array($state['workers'])) {
                $state['workers'] = [];
            }
            $state['updated_at'] = time();
            file_put_contents(
                $activeFile,
                json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
            );
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
