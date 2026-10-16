<?php

declare(strict_types=1);

namespace Probe\Worker;

use JsonException;

/**
 * One JSON object per connection, terminated by a newline.
 * Unix sockets stay local to the machine. The payload is the agent contract.
 */
final class Protocol
{
    public const MAX_LINE_BYTES = 2_000_000;

    /**
     * @param  array<string, mixed>  $message
     */
    public static function encode(array $message): string
    {
        return json_encode(
            $message,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        )."\n";
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(string $line): array
    {
        $line = trim($line);
        if ($line === '') {
            throw new WorkerException('empty worker message');
        }

        try {
            $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new WorkerException('invalid worker JSON: '.$exception->getMessage(), 0, $exception);
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new WorkerException('worker message must be a JSON object');
        }

        return $data;
    }

    /** @param  resource  $stream */
    public static function readLine($stream): string
    {
        $buffer = '';

        while (! feof($stream)) {
            $chunk = fread($stream, 8192);
            if ($chunk === false) {
                break;
            }

            if ($chunk === '') {
                $meta = stream_get_meta_data($stream);
                if (! empty($meta['timed_out'])) {
                    throw new WorkerTimeout('timed out reading from the Probe worker');
                }
                break;
            }

            $buffer .= $chunk;
            if (strlen($buffer) > self::MAX_LINE_BYTES) {
                throw new WorkerException('worker message exceeds the size limit');
            }

            if (str_contains($buffer, "\n")) {
                break;
            }
        }

        $position = strpos($buffer, "\n");
        if ($position === false) {
            if ($buffer === '') {
                throw new WorkerException('worker closed the connection');
            }

            return trim($buffer);
        }

        return substr($buffer, 0, $position);
    }

    /**
     * @param  resource  $stream
     * @param  array<string, mixed>  $message
     */
    public static function write($stream, array $message): void
    {
        $data = self::encode($message);
        $written = 0;
        $length = strlen($data);

        while ($written < $length) {
            $count = fwrite($stream, substr($data, $written));
            if ($count === false || $count === 0) {
                throw new WorkerException('could not write to the Probe worker');
            }
            $written += $count;
        }
    }
}
