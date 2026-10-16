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
        public readonly ?string $url = null,
        public readonly ?string $bridge = null,
        public readonly ?int $cases = null,
        public readonly ?int $seed = null,
        public readonly ?string $engine = null,
        public readonly ?string $contains = null,
        public readonly ?string $absent = null,
        public readonly ?int $limit = null,
        public readonly ?string $property = null,
        public readonly ?string $steps = null,
        public readonly ?int $httpStatus = null,
    ) {}
}
