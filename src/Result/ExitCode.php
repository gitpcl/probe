<?php

declare(strict_types=1);

namespace Probe\Result;

final class ExitCode
{
    public static function fromStatus(string $status): int
    {
        return match ($status) {
            'passed' => 0,
            'failed' => 1,
            default => 2,
        };
    }
}
