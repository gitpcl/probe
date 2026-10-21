<?php

declare(strict_types=1);

namespace Probe\Console;

use RuntimeException;

/** A JSON-RPC error: the protocol code travels as the exception code. */
final class McpException extends RuntimeException
{
    public function __construct(int $code, string $message)
    {
        parent::__construct($message, $code);
    }
}
