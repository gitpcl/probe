<?php

declare(strict_types=1);

namespace Probe\Worker;

final class Client
{
    /**
     * @param  array<string, mixed>  $request
     */
    public function requestLine(string $socket, array $request, float $timeoutSeconds): string
    {
        $timeoutSeconds = max(0.2, $timeoutSeconds);
        $errno = 0;
        $error = '';
        $stream = @stream_socket_client(
            'unix://'.$socket,
            $errno,
            $error,
            min(5.0, $timeoutSeconds)
        );

        if ($stream === false) {
            throw new WorkerException($error !== '' ? $error : 'could not connect to the Probe worker');
        }

        $seconds = (int) floor($timeoutSeconds);
        $micros = (int) round(($timeoutSeconds - $seconds) * 1_000_000);
        stream_set_timeout($stream, $seconds, $micros);

        try {
            Protocol::write($stream, $request);

            return Protocol::readLine($stream);
        } finally {
            fclose($stream);
        }
    }
}
