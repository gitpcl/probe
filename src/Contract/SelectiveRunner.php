<?php

declare(strict_types=1);

namespace Probe\Contract;

/**
 * Phase 2. Test-impact selection for run_affected.
 * AffectedRunner is the implementation.
 */
interface SelectiveRunner
{
    /**
     * @param  list<string>  $paths
     * @return array<string, mixed>
     */
    public function runAffected(array $paths): array;
}
