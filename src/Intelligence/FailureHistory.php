<?php

declare(strict_types=1);

namespace Probe\Intelligence;

use Throwable;

/** Recent failing test paths. The heuristic selector ranks these higher. */
final class FailureHistory
{
    public function __construct(private readonly string $path) {}

    /** @return list<string> */
    public function recent(): array
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

        $paths = [];
        foreach ($data as $path) {
            if (is_string($path) && $path !== '') {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /** @param  list<string>  $paths */
    public function push(array $paths): void
    {
        $merged = $this->recent();
        foreach ($paths as $path) {
            if ($path !== '') {
                $merged[] = $path;
            }
        }

        $merged = array_values(array_unique($merged));
        if (count($merged) > 30) {
            $merged = array_slice($merged, -30);
        }

        $directory = dirname($this->path);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return;
        }

        file_put_contents(
            $this->path,
            json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }
}
