<?php

declare(strict_types=1);

namespace Probe\Contract;

/**
 * Phase 4. Alloy, TLA+, Dafny, and Lean bridges.
 * Counterexamples are ordinary Probe failures. Missing binaries are errors.
 */
interface FormalBridge
{
    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function check(array $request): array;
}
