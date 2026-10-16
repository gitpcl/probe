<?php

declare(strict_types=1);

namespace Probe\Tests;

use PHPUnit\Framework\TestCase;
use Probe\Worker\Client;

final class EchoServerTest extends TestCase
{
    private string $socket = '';

    /** @var resource|null */
    private $process = null;

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);
            if (($status['running'] ?? false) === true && is_file($this->socket)) {
                try {
                    (new Client())->requestLine($this->socket, ['tool' => 'shutdown'], 1);
                } catch (\Throwable) {
                    // The process is stopped below.
                }
            }

            proc_terminate($this->process);
            proc_close($this->process);
        }

        if ($this->socket !== '' && file_exists($this->socket)) {
            @unlink($this->socket);
        }

        parent::tearDown();
    }

    public function test_server_answers_two_requests_then_shuts_down(): void
    {
        $this->socket = sys_get_temp_dir().'/probe-echo-'.bin2hex(random_bytes(4)).'.sock';
        $script = dirname(__DIR__).'/tests/Fixtures/echo-server.php';
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($this->socket);

        $this->process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        $this->assertIsResource($this->process);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + 5;
        while (! is_file($this->socket) && microtime(true) < $deadline) {
            usleep(10_000);
        }

        $client = new Client();
        $first = json_decode($client->requestLine($this->socket, ['tool' => 'probe', 'code' => 'one'], 2), true, 512, JSON_THROW_ON_ERROR);
        $second = json_decode($client->requestLine($this->socket, ['tool' => 'probe', 'code' => 'two'], 2), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('one', $first['value']);
        $this->assertSame('two', $second['value']);
        $this->assertSame('passed', $first['status']);

        $shutdown = json_decode($client->requestLine($this->socket, ['tool' => 'shutdown'], 2), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('passed', $shutdown['status']);

        $deadline = microtime(true) + 3;
        do {
            $status = proc_get_status($this->process);
            if (($status['running'] ?? true) === false) {
                break;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        $this->assertFalse($status['running'] ?? true);
    }
}
