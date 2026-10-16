<?php

declare(strict_types=1);

namespace Probe\Runner;

/**
 * Remembers the latest snippet wrapper so failure lines point at the
 * user's code instead of the generated file.
 */
final class SnippetSource
{
    public static int $lineOffset = 0;

    public static string $code = '';
}
