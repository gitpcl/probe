<?php

declare(strict_types=1);

namespace Probe\Console;

use Probe\Probe;
use Probe\Project;
use Probe\Worker\Pool;
use RuntimeException;
use Throwable;

/**
 * MCP server over stdio: newline-delimited JSON-RPC 2.0, one process for the whole
 * agent session. Each tool call is one socket round trip to the warm worker, so an
 * agent pays no PHP startup per probe. Runs on Probe's own autoloader like the CLI
 * client; the worker is where Laravel lives.
 */
final class McpServer
{
    public const PROTOCOL = '2025-06-18';

    private const INVALID_REQUEST = -32600;

    private const METHOD_NOT_FOUND = -32601;

    private const INVALID_PARAMS = -32602;

    private const INTERNAL = -32603;

    /** @var array<string, array{description: string, keys: list<string>, required: list<string>}> */
    private const TOOLS = [
        'probe' => ['description' => 'Run a PHP snippet inside the warm Laravel app. Returns compact JSON: status, duration_ms, failures, value.', 'keys' => ['code', 'timeout_ms'], 'required' => ['code']],
        'get_failures' => ['description' => 'Detail (type, file, line, trace) for the first failure of the last result; id picks another.', 'keys' => ['id'], 'required' => []],
        'rerun_failed' => ['description' => 'Run the last failed probe or check again.', 'keys' => [], 'required' => []],
        'run_affected' => ['description' => 'Run the Pest or PHPUnit files impacted by the given paths (array or comma list); no paths means the git diff.', 'keys' => ['paths', 'tests'], 'required' => []],
        'probe_browser' => ['description' => 'Fetch a page and assert contains, absent, http_status; optional goto, fill, click steps.', 'keys' => ['url', 'contains', 'absent', 'http_status', 'steps', 'engine'], 'required' => ['url']],
        'mutate_check' => ['description' => 'Flip operators in one file and report which mutants survive the covering tests.', 'keys' => ['file', 'limit', 'tests'], 'required' => ['file']],
        'property_check' => ['description' => 'Run a snippet or a built-in property against generated cases.', 'keys' => ['property', 'code', 'cases', 'seed'], 'required' => []],
        'architecture_check' => ['description' => 'Run the architecture tests and configured forbid_use rules.', 'keys' => [], 'required' => []],
        'formal_check' => ['description' => 'Run an Alloy, TLA+, Dafny or Lean spec through the installed prover.', 'keys' => ['file', 'bridge', 'code'], 'required' => []],
        'auto_probe' => ['description' => 'Pick and run the most relevant tests for the given paths.', 'keys' => ['paths', 'limit'], 'required' => []],
        'status' => ['description' => 'Worker pool status.', 'keys' => [], 'required' => []],
        'cold' => ['description' => 'Replace the warm worker with a fresh one.', 'keys' => [], 'required' => []],
        'stop' => ['description' => 'Stop the warm worker.', 'keys' => [], 'required' => []],
    ];

    public function __construct(
        private readonly Pool $pool = new Pool,
        private ?string $root = null,
    ) {}

    public static function main(): int
    {
        return (new self)->serve(STDIN, STDOUT);
    }

    /**
     * @param  resource  $in
     * @param  resource  $out
     */
    public function serve($in, $out): int
    {
        while (($line = fgets($in)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $message = json_decode($line, true);
            $response = is_array($message)
                ? $this->handle($message)
                : $this->error(null, self::INVALID_REQUEST, 'invalid JSON');

            if ($response !== null) {
                fwrite($out, json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
                fflush($out);
            }
        }

        return 0;
    }

    /**
     * One JSON-RPC message in, one response out; null for a notification.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null
     */
    public function handle(array $message): ?array
    {
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        if (! is_string($method)) {
            return $this->error($id, self::INVALID_REQUEST, 'method is required');
        }

        if (str_starts_with($method, 'notifications/')) {
            return null;
        }

        try {
            $result = match ($method) {
                'initialize' => [
                    'protocolVersion' => self::PROTOCOL,
                    'capabilities' => ['tools' => (object) []],
                    'serverInfo' => ['name' => 'probe', 'version' => Probe::VERSION],
                ],
                'ping' => (object) [],
                'tools/list' => ['tools' => $this->tools()],
                'tools/call' => $this->call($params),
                default => throw new McpException(self::METHOD_NOT_FOUND, 'unknown method '.$method),
            };
        } catch (McpException $exception) {
            return $this->error($id, $exception->getCode(), $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->error($id, self::INTERNAL, $exception->getMessage());
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return list<array<string, mixed>> */
    private function tools(): array
    {
        $types = [
            'code' => ['type' => 'string'], 'timeout_ms' => ['type' => 'integer'], 'id' => ['type' => 'string'],
            'paths' => ['type' => ['array', 'string'], 'items' => ['type' => 'string']],
            'tests' => ['type' => 'array', 'items' => ['type' => 'string']],
            'url' => ['type' => 'string'], 'contains' => ['type' => 'string'], 'absent' => ['type' => 'string'],
            'http_status' => ['type' => 'integer'], 'steps' => ['type' => 'array', 'items' => ['type' => 'object']],
            'engine' => ['type' => 'string', 'enum' => ['auto', 'lightpanda', 'playwright']],
            'file' => ['type' => 'string'], 'limit' => ['type' => 'integer'], 'property' => ['type' => 'string'],
            'cases' => ['type' => 'integer'], 'seed' => ['type' => 'integer'], 'bridge' => ['type' => 'string'],
        ];

        $tools = [];
        foreach (self::TOOLS as $name => $tool) {
            $properties = [];
            foreach ($tool['keys'] as $key) {
                $properties[$key] = $types[$key];
            }

            $tools[] = [
                'name' => $name,
                'description' => $tool['description'],
                'inputSchema' => ['type' => 'object', 'properties' => (object) $properties, 'required' => $tool['required']],
            ];
        }

        return $tools;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function call(array $params): array
    {
        $name = $params['name'] ?? null;
        if (! is_string($name) || ! isset(self::TOOLS[$name])) {
            throw new McpException(self::INVALID_PARAMS, 'unknown tool '.(is_string($name) ? $name : ''));
        }

        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        foreach (self::TOOLS[$name]['required'] as $key) {
            if (! isset($arguments[$key]) || $arguments[$key] === '') {
                throw new McpException(self::INVALID_PARAMS, $name.' needs '.$key);
            }
        }

        $root = $this->root();
        $payload = match ($name) {
            'status' => $this->pool->status($root),
            'cold' => $this->cold($root),
            'stop' => $this->stop($root),
            default => $this->dispatch($root, $name, $arguments),
        };

        $status = (string) ($payload['status'] ?? 'error');

        return [
            'content' => [['type' => 'text', 'text' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            'isError' => ! in_array($status, ['passed', 'running', 'stopped'], true),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function dispatch(string $root, string $tool, array $arguments): array
    {
        $request = ['tool' => $tool];
        foreach (self::TOOLS[$tool]['keys'] as $key) {
            if (! array_key_exists($key, $arguments) || $arguments[$key] === null || $arguments[$key] === '') {
                continue;
            }

            $value = $arguments[$key];
            if ($key === 'steps' && is_string($value)) {
                $value = json_decode($value, true);
                if (! is_array($value)) {
                    throw new McpException(self::INVALID_PARAMS, 'steps must be a JSON array');
                }
            }

            $request[$key] = $value;
        }

        $timeout = isset($arguments['timeout_ms']) && is_numeric($arguments['timeout_ms']) ? (int) $arguments['timeout_ms'] : null;
        $decoded = json_decode($this->pool->dispatch($root, $request, $timeout), true);

        return is_array($decoded) ? $decoded : ['status' => 'error', 'duration_ms' => 0, 'failures' => [['id' => 'worker', 'message' => 'worker returned invalid JSON']]];
    }

    /** @return array<string, mixed> */
    private function cold(string $root): array
    {
        $this->pool->ensure($root, cold: true);

        return ['status' => 'passed', 'duration_ms' => 0, 'failures' => [], 'recycled' => true];
    }

    /** @return array<string, mixed> */
    private function stop(string $root): array
    {
        $this->pool->stop($root);

        return ['status' => 'passed', 'duration_ms' => 0, 'failures' => [], 'stopped' => true];
    }

    private function root(): string
    {
        try {
            return $this->root ??= Project::find();
        } catch (RuntimeException $exception) {
            throw new McpException(self::INTERNAL, $exception->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
