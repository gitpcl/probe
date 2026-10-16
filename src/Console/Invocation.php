<?php

declare(strict_types=1);

namespace Probe\Console;

final class Invocation
{
    public function __construct(
        public readonly string $tool = 'probe',
        public readonly ?string $code = null,
        public readonly ?string $file = null,
        public readonly ?int $timeoutMs = null,
        public readonly ?string $id = null,
        public readonly ?string $paths = null,
        public readonly bool $cold = false,
        public readonly bool $benchmark = false,
        public readonly bool $stop = false,
        public readonly bool $status = false,
        public readonly bool $help = false,
        public readonly bool $version = false,
        public readonly int $samples = 20,
    ) {}
}
