<?php

declare(strict_types=1);

namespace Probe\Intelligence;

interface Selector
{
    public function name(): string;

    /**
     * @param  list<string>  $paths
     * @param  array<string, mixed>  $context
     * @return list<string>
     */
    public function select(array $paths, array $context): array;
}
