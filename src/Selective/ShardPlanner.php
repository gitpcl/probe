<?php

declare(strict_types=1);

namespace Probe\Selective;

/**
 * Duration-aware bin packing for parallel PHPUnit shards.
 * Unknown files weigh 1 ms so a first run still spreads work.
 */
final class ShardPlanner
{
    /**
     * @param  list<string>  $files
     * @param  array<string, int>  $durations
     * @return list<list<string>>
     */
    public function plan(array $files, array $durations, int $workers): array
    {
        if ($files === []) {
            return [];
        }

        $workers = max(1, min($workers, count($files)));
        if ($workers === 1) {
            return [$files];
        }

        usort($files, static function (string $left, string $right) use ($durations): int {
            return ($durations[$right] ?? 1) <=> ($durations[$left] ?? 1);
        });

        $bins = [];
        for ($index = 0; $index < $workers; $index++) {
            $bins[] = ['weight' => 0, 'files' => []];
        }

        foreach ($files as $file) {
            usort($bins, static fn (array $left, array $right): int => $left['weight'] <=> $right['weight']);
            $bins[0]['files'][] = $file;
            $bins[0]['weight'] += $durations[$file] ?? 1;
        }

        $shards = [];
        foreach ($bins as $bin) {
            if ($bin['files'] !== []) {
                $shards[] = $bin['files'];
            }
        }

        return $shards;
    }
}
