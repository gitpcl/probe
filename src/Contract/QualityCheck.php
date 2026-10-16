<?php

declare(strict_types=1);

namespace Probe\Contract;

/**
 * Phase 3. Mutation, property, and architecture checks.
 * Phase 1 answers mutate_check and property_check with status unsupported.
 */
interface QualityCheck
{
    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function check(array $request): array;
}
