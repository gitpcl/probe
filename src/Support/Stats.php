<?php

declare(strict_types=1);

namespace Probe\Support;

final class Stats
{
    public static function elapsedMs(int $startedNs): int
    {
        return (int) round((hrtime(true) - $startedNs) / 1_000_000);
    }

    /**
     * @param  list<int>  $samples
     */
    public static function median(array $samples): int
    {
        if ($samples === []) {
            return 0;
        }

        sort($samples);
        $count = count($samples);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $samples[$middle];
        }

        return (int) round(($samples[$middle - 1] + $samples[$middle]) / 2);
    }

    /**
     * @param  list<int>  $samples
     */
    public static function percentile(array $samples, float $percentile): int
    {
        if ($samples === []) {
            return 0;
        }

        sort($samples);
        $index = (int) ceil($percentile * count($samples)) - 1;
        $index = max(0, min(count($samples) - 1, $index));

        return $samples[$index];
    }
}
