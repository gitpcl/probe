<?php

declare(strict_types=1);

namespace Probe\Tests;

use PHPUnit\Framework\TestCase;
use Probe\Console\McpServer;
use Probe\Probe;
use Probe\Worker\Pool;

final class McpServerTest extends TestCase
{
    private string $root;

    private Pool $pool;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__).'/tests/Fixtures/app';
        $this->pool = new Pool;
        $this->pool->stop($this->root);
    }

    protected function tearDown(): void
    {
        $this->pool->stop($this->root);
    }

    public function test_initialize_lists_tools_and_runs_a_probe(): void
    {
        $server = new McpServer($this->pool, $this->root);

        $init = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]);
        $this->assertSame(McpServer::PROTOCOL, $init['result']['protocolVersion']);
        $this->assertSame(Probe::VERSION, $init['result']['serverInfo']['version']);

        $this->assertNull($server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));

        $list = $server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
        $names = array_column($list['result']['tools'], 'name');
        $this->assertContains('probe', $names);
        $this->assertContains('run_affected', $names);
        $this->assertSame(['code'], $list['result']['tools'][0]['inputSchema']['required']);

        $call = $server->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'probe', 'arguments' => ['code' => 'return 3;']]]);
        $this->assertFalse($call['result']['isError'], json_encode($call));
        $this->assertStringContainsString('"value":3', $call['result']['content'][0]['text']);

        $failed = $server->handle(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'probe', 'arguments' => ['code' => 'throw new RuntimeException("boom");']]]);
        $this->assertTrue($failed['result']['isError']);
        $this->assertStringContainsString('boom', $failed['result']['content'][0]['text']);

        $status = $server->handle(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'status']]);
        $this->assertStringContainsString('"status":"running"', $status['result']['content'][0]['text']);
    }

    public function test_protocol_errors_have_json_rpc_codes(): void
    {
        $server = new McpServer($this->pool, $this->root);

        $unknown = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/list']);
        $this->assertSame(-32601, $unknown['error']['code']);

        $tool = $server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'nope']]);
        $this->assertSame(-32602, $tool['error']['code']);

        $missing = $server->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'probe', 'arguments' => []]]);
        $this->assertSame(-32602, $missing['error']['code']);
        $this->assertSame('probe needs code', $missing['error']['message']);
    }

    public function test_the_binary_speaks_newline_delimited_json_rpc_over_stdio(): void
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__).'/bin/probe-mcp'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
        );
        $this->assertIsResource($process);

        $lines = [
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"0"}}}',
            '{"jsonrpc":"2.0","method":"notifications/initialized"}',
            '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"probe","arguments":{"code":"return 5;"}}}',
        ];
        fwrite($pipes[0], implode("\n", $lines)."\n");
        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $responses = array_values(array_filter(explode("\n", (string) $output)));
        $this->assertCount(2, $responses, (string) $output);
        $this->assertSame(1, json_decode($responses[0], true)['id']);
        $this->assertStringContainsString('"value":5', json_decode($responses[1], true)['result']['content'][0]['text']);
    }
}
