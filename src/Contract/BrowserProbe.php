<?php

declare(strict_types=1);

namespace Probe\Contract;

/**
 * Phase 2. Hybrid browser probes (Lightpanda by default, Playwright as fallback).
 * The agent tool name is probe_browser. Phase 1 answers that tool with status unsupported.
 */
interface BrowserProbe
{
    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function run(array $request): array;
}
