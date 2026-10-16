<?php

declare(strict_types=1);

namespace Probe\Tests;

use PHPUnit\Framework\TestCase;
use Probe\Support\Stats;

final class StatsTest extends TestCase
{
    public function test_median_handles_odd_and_even_counts(): void
    {
        $this->assertSame(3, Stats::median([5, 1, 3]));
        $this->assertSame(3, Stats::median([1, 2, 4, 8]));
        $this->assertSame(0, Stats::median([]));
    }

    public function test_percentile_uses_the_nearest_rank(): void
    {
        $this->assertSame(95, Stats::percentile([10, 20, 95, 100], 0.95));
    }
}
