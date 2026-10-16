<?php

declare(strict_types=1);

namespace Probe\Contract;

/**
 * Phase 3. Mutation, property, and architecture checks.
 * Each check returns the same compact Probe result as a PHP probe.
 */
interface QualityCheck
{
    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function check(array $request): array;
}
