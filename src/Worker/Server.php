<?php

declare(strict_types=1);

namespace Probe\Worker;

use Throwable;

/**
 * Accepts one JSON request per connection and keeps the PHP process alive.
 * stream_select wakes about once a second so a stop flag can end the loop.
 */
final class Server
{
    private bool $stop = false;

    private ?string $socketPath = null;

    public function stop(): void
    {
        $this->stop = true;
    }

    public function listen(string $socketPath, callable $handler): void
    {
        $directory = dirname($socketPath);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new WorkerException('Could not create '.$directory);
        }

        if (file_exists($socketPath)) {
            unlink($socketPath);
        }

        $errno = 0;
        $error = '';
        $server = stream_socket_server('unix://'.$socketPath, $errno, $error);
        if ($server === false) {
            throw new WorkerException('Could not listen on '.$socketPath.': '.$error);
        }

        @chmod($socketPath, 0600);
        stream_set_blocking($server, false);
        $this->socketPath = $socketPath;
        $this->stop = false;

        $owner = getmypid();
        register_shutdown_function(function () use ($socketPath, $owner): void {
            // A forked probe child exiting must not remove the worker's socket.
            if (getmypid() !== $owner) {
                return;
            }

            if ($this->socketPath !== null && file_exists($socketPath)) {
                @unlink($socketPath);
            }
        });

        try {
            while (! $this->stop) {
                $read = [$server];
                $write = null;
                $except = null;
                $ready = @stream_select($read, $write, $except, 1);
                if ($ready === false || $ready === 0) {
                    continue;
                }

                $connection = @stream_socket_accept($server, 0);
                if ($connection === false) {
                    continue;
                }

                $this->handleConnection($connection, $handler);
                fclose($connection);
            }
        } finally {
            fclose($server);
            if (file_exists($socketPath)) {
                @unlink($socketPath);
            }
            $this->socketPath = null;
        }
    }

    /** @param  resource  $connection */
    private function handleConnection($connection, callable $handler): void
    {
        stream_set_blocking($connection, true);
        stream_set_timeout($connection, 5);

        try {
            $line = Protocol::readLine($connection);
            $request = Protocol::decode($line);
            $response = $handler($request);
            if (! is_array($response)) {
                $response = [
                    'status' => 'error',
                    'duration_ms' => 0,
                    'failures' => [['id' => 'handler', 'message' => 'handler returned a non-array']],
                ];
            }
        } catch (Throwable $exception) {
            $message = trim(preg_replace('/\s+/', ' ', $exception->getMessage()) ?? $exception->getMessage());
            $response = [
                'status' => 'error',
                'duration_ms' => 0,
                'failures' => [['id' => 'protocol', 'message' => $message !== '' ? $message : 'invalid request']],
            ];
        }

        try {
            Protocol::write($connection, $response);
        } catch (Throwable) {
            // The client stopped waiting. Its next call gets a fresh answer; this worker stays up.
        }
    }
}
