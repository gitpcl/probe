<?php

declare(strict_types=1);

namespace Probe\Selective;

use Throwable;

/** Remembers PHPUnit file durations so the next shard plan can balance them. */
final class DurationBook
{
    public function __construct(private readonly string $path) {}

    /** @return array<string, int> */
    public function all(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        try {
            $data = json_decode((string) file_get_contents($this->path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($data)) {
            return [];
        }

        $times = [];
        foreach ($data as $file => $ms) {
            if (is_string($file) && is_numeric($ms)) {
                $times[$file] = (int) $ms;
            }
        }

        return $times;
    }

    /** @param  array<string, int>  $times */
    public function record(array $times): void
    {
        if ($times === []) {
            return;
        }

        $directory = dirname($this->path);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return;
        }

        $all = $this->all();
        foreach ($times as $file => $ms) {
            if (is_string($file)) {
                $all[$file] = (int) $ms;
            }
        }

        file_put_contents(
            $this->path,
            json_encode($all, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }
}
