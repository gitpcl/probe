<?php

declare(strict_types=1);

namespace Probe\Result;

use Probe\Runner\SnippetSource;
use Throwable;

final class Failure
{
    public function __construct(
        public readonly string $id,
        public readonly string $message,
        public readonly ?string $type = null,
        public readonly ?string $file = null,
        public readonly ?int $line = null,
        public readonly ?string $trace = null,
    ) {}

    public static function fromThrowable(Throwable $exception, string $id = '1'): self
    {
        $file = $exception->getFile();
        $line = $exception->getLine();
        $message = $exception->getMessage();

        if (str_contains($file, 'probe-snippet-')) {
            $line = max(1, $line - SnippetSource::$lineOffset);
            $message = str_replace($exception->getFile(), 'probe.php', $message);
            $message = preg_replace('/ on line \d+/', ' on line '.$line, $message) ?? $message;
            $file = 'probe.php';
        }

        if (trim($message) === '') {
            $message = $exception::class;
        }

        return new self(
            id: $id,
            message: self::flatten($message, 4000),
            type: $exception::class,
            file: $file,
            line: $line,
            trace: self::trace($exception),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: (string) ($row['id'] ?? '1'),
            message: (string) ($row['message'] ?? ''),
            type: isset($row['type']) ? (string) $row['type'] : null,
            file: isset($row['file']) ? (string) $row['file'] : null,
            line: isset($row['line']) ? (int) $row['line'] : null,
            trace: isset($row['trace']) ? (string) $row['trace'] : null,
        );
    }

    /** The same failure with another message: type, file, line and trace stay. */
    public function withMessage(string $message): self
    {
        return new self(
            id: $this->id,
            message: $message,
            type: $this->type,
            file: $this->file,
            line: $this->line,
            trace: $this->trace,
        );
    }

    /**
     * The compact line keeps the error text and drops the connection-and-SQL
     * tail Laravel appends to a query exception; the detailed form keeps it all.
     *
     * @return array{id: string, message: string}
     */
    public function compact(): array
    {
        $message = $this->message;
        $tail = strpos($message, ' (Connection: ');
        if ($tail !== false && str_ends_with(rtrim($message), ')')) {
            $message = substr($message, 0, $tail);
        }

        return [
            'id' => $this->id,
            'message' => self::flatten($message, 180),
        ];
    }

    /** @return array<string, int|string> */
    public function detailed(): array
    {
        $detail = [
            'id' => $this->id,
            'message' => $this->message,
        ];

        if ($this->type !== null) {
            $detail['type'] = $this->type;
        }

        if ($this->file !== null) {
            $detail['file'] = $this->file;
        }

        if ($this->line !== null) {
            $detail['line'] = $this->line;
        }

        if ($this->trace !== null && $this->trace !== '') {
            $detail['trace'] = $this->trace;
        }

        return $detail;
    }

    private static function flatten(string $message, int $limit): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
        $length = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
        if ($length <= $limit) {
            return $message;
        }

        $slice = function_exists('mb_substr')
            ? mb_substr($message, 0, $limit - 3)
            : substr($message, 0, $limit - 3);

        return $slice.'...';
    }

    /**
     * The exception trace from the application frames the snippet reached, cut at
     * the first Probe frame: everything below it is the runner and the worker.
     * The generated snippet file's own frames are left out too.
     */
    private static function trace(Throwable $exception): string
    {
        // src/ rather than the package root: in this checkout the root also holds vendor/.
        $source = dirname(__DIR__).'/';
        $lines = [];
        foreach (explode("\n", $exception->getTraceAsString()) as $line) {
            if (str_contains($line, 'probe-snippet-')) {
                continue;
            }

            // Frames below the package boundary are the worker's own stack
            // (command, container, console): the application's story ends here.
            if (str_contains($line, $source)) {
                break;
            }

            $space = strpos($line, ' ');
            $lines[] = '#'.count($lines).($space === false ? '' : substr($line, $space));
        }

        $trace = implode("\n", $lines);
        if (strlen($trace) <= 1500) {
            return $trace;
        }

        return substr($trace, 0, 1497).'...';
    }
}
