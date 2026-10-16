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

    /** @return array{id: string, message: string} */
    public function compact(): array
    {
        return [
            'id' => $this->id,
            'message' => self::flatten($this->message, 180),
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

    private static function trace(Throwable $exception): string
    {
        $trace = $exception->getTraceAsString();
        if (strlen($trace) <= 1500) {
            return $trace;
        }

        return substr($trace, 0, 1497).'...';
    }
}
