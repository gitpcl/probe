<?php

declare(strict_types=1);

namespace Probe\Console;

use Probe\Probe;
use Probe\Project;
use Probe\Result\ExitCode;
use Probe\Support\Stats;
use Probe\Worker\Pool;
use RuntimeException;
use Throwable;

/**
 * Shared by vendor/bin/probe and `php artisan probe`.
 * The binary path is the fast one: it does not boot Laravel in the client.
 */
final class Dispatcher
{
    /** @var callable(string): void */
    private $writer;

    public function __construct(private readonly Pool $pool = new Pool)
    {
        $this->writer = static function (string $json): void {
            fwrite(STDOUT, $json);
        };
    }

    /** @param  list<string>  $argv */
    public static function main(array $argv): int
    {
        return (new self)->run(Argv::parse($argv));
    }

    /** @param  callable(string): void|null  $writer */
    public function run(Invocation $invocation, ?callable $writer = null): int
    {
        if ($writer !== null) {
            $this->writer = $writer;
        }

        if ($invocation->help) {
            ($this->writer)(self::help());

            return 0;
        }

        if ($invocation->version) {
            ($this->writer)(Probe::VERSION."\n");

            return 0;
        }

        if ($invocation->error !== null) {
            return $this->emitError('usage', $invocation->error);
        }

        try {
            $root = Project::find();
        } catch (RuntimeException $exception) {
            return $this->emitError('project', $exception->getMessage());
        }

        if ($invocation->stop || $invocation->tool === 'stop') {
            $this->pool->stop($root);
            $this->emit([
                'status' => 'passed',
                'duration_ms' => 0,
                'failures' => [],
                'stopped' => true,
            ]);

            return 0;
        }

        if ($invocation->status || $invocation->tool === 'status') {
            $this->emit($this->pool->status($root));

            return 0;
        }

        if ($invocation->tool === 'cold' && $invocation->code === null) {
            $this->pool->stop($root);
            $this->emit(['status' => 'passed', 'duration_ms' => 0, 'failures' => [], 'recycled' => true]);

            return 0;
        }

        if ($invocation->benchmark) {
            try {
                $report = $this->benchmark($root, $invocation->samples);
            } catch (Throwable $exception) {
                return $this->emitError('benchmark', $exception->getMessage());
            }

            $this->emit($report);

            return ($report['met_target'] ?? false) === true ? 0 : 2;
        }

        $code = $invocation->code;
        if ($invocation->file !== null && $invocation->tool === 'probe') {
            if (! is_file($invocation->file)) {
                return $this->emitError('file', 'snippet file not found');
            }

            $code = file_get_contents($invocation->file);
            if ($code === false) {
                return $this->emitError('file', 'could not read the snippet file');
            }
        }

        if ($invocation->tool === 'probe' && ($code === null || trim($code) === '')) {
            return $this->emitError('code', 'missing probe code');
        }

        try {
            if ($invocation->cold) {
                $this->pool->stop($root);
            }

            $request = [
                'tool' => $invocation->tool,
                'timeout_ms' => $invocation->timeoutMs,
            ];

            if ($code !== null) {
                $request['code'] = $code;
            }

            if ($invocation->id !== null && $invocation->tool === 'get_failures') {
                $request['id'] = $invocation->id;
            }

            if ($invocation->paths !== null && $invocation->paths !== '') {
                $request['paths'] = array_values(array_filter(
                    array_map('trim', explode(',', $invocation->paths)),
                    static fn (string $path): bool => $path !== '',
                ));
            }

            if ($invocation->tests !== null && $invocation->tests !== '') {
                $request['tests'] = array_values(array_filter(
                    array_map('trim', explode(',', $invocation->tests)),
                    static fn (string $test): bool => $test !== '',
                ));
            }

            if ($invocation->file !== null && $invocation->tool !== 'probe') {
                $request['file'] = $invocation->file;
            }

            foreach ([
                'url' => $invocation->url,
                'bridge' => $invocation->bridge,
                'engine' => $invocation->engine,
                'contains' => $invocation->contains,
                'absent' => $invocation->absent,
                'property' => $invocation->property,
                'scope' => $invocation->scope,
                'project' => $invocation->project,
                'spec' => $invocation->spec,
                'auth' => $invocation->auth,
            ] as $key => $value) {
                if (is_string($value) && $value !== '') {
                    $request[$key] = $value;
                }
            }

            if ($invocation->cases !== null) {
                $request['cases'] = $invocation->cases;
            }

            if ($invocation->seed !== null) {
                $request['seed'] = $invocation->seed;
            }

            if ($invocation->limit !== null) {
                $request['limit'] = $invocation->limit;
            }

            if ($invocation->httpStatus !== null) {
                $request['http_status'] = $invocation->httpStatus;
            }

            if ($invocation->level !== null) {
                $request['level'] = $invocation->level;
            }

            if ($invocation->routes !== null && $invocation->routes !== '') {
                $request['routes'] = array_values(array_filter(
                    array_map('trim', explode(',', $invocation->routes)),
                    static fn (string $route): bool => $route !== '',
                ));
            }

            if ($invocation->steps !== null && $invocation->steps !== '') {
                $steps = json_decode($invocation->steps, true);
                if (! is_array($steps)) {
                    return $this->emitError('steps', 'steps must be a JSON array');
                }

                $request['steps'] = $steps;
            }

            $line = $this->pool->dispatch($root, $request, $invocation->timeoutMs);
        } catch (Throwable $exception) {
            return $this->emitError('worker', $exception->getMessage());
        }

        ($this->writer)(rtrim($line)."\n");
        $decoded = json_decode($line, true);
        $status = is_array($decoded) ? (string) ($decoded['status'] ?? 'error') : 'error';

        return ExitCode::fromStatus($status);
    }

    /** @return array<string, mixed> */
    public function benchmark(string $root, int $samples = 20): array
    {
        $samples = max(1, $samples);
        $script = dirname(__DIR__, 2).'/bin/probe.php';
        if (! is_file($script)) {
            throw new RuntimeException('Probe could not find bin/probe.php.');
        }

        $this->pool->stop($root);
        $coldMs = $this->timeProcess($root, $script);

        $process = [];
        for ($index = 0; $index < $samples; $index++) {
            $process[] = $this->timeProcess($root, $script);
        }

        // The shell entry answers a bare snippet over nc without starting PHP on the client side.
        $shell = [];
        $fast = dirname(__DIR__, 2).'/bin/probe';
        if (is_executable($fast) && trim((string) shell_exec('command -v nc 2>/dev/null')) !== '') {
            for ($index = 0; $index < $samples; $index++) {
                $shell[] = $this->timeProcess($root, $fast, viaShell: true);
            }
        }

        $roundtripUs = [];
        for ($index = 0; $index < $samples; $index++) {
            $started = hrtime(true);
            $line = $this->pool->dispatch($root, [
                'tool' => 'probe',
                'code' => 'return 1;',
            ], 10000);
            $decoded = json_decode($line, true);
            if (! is_array($decoded) || ($decoded['status'] ?? null) !== 'passed') {
                throw new RuntimeException('warm round trip failed: '.$line);
            }
            $roundtripUs[] = (int) round((hrtime(true) - $started) / 1_000);
        }

        $processMedian = Stats::median($process);

        $report = [
            'status' => $processMedian < Probe::WARM_TARGET_MS ? 'passed' : 'failed',
            'cold_ms' => $coldMs,
            'process_median_ms' => $processMedian,
            'process_min_ms' => min($process),
            'process_p95_ms' => Stats::percentile($process, 0.95),
            'roundtrip_median_ms' => round(Stats::median($roundtripUs) / 1000, 3),
            'roundtrip_min_ms' => round(min($roundtripUs) / 1000, 3),
            'samples' => $samples,
            'target_ms' => Probe::WARM_TARGET_MS,
            'met_target' => $processMedian < Probe::WARM_TARGET_MS,
            'php' => PHP_VERSION,
        ];

        if ($shell !== []) {
            $report['shell_median_ms'] = Stats::median($shell);
            $report['shell_min_ms'] = min($shell);
        }

        return $report;
    }

    private function timeProcess(string $root, string $script, bool $viaShell = false): int
    {
        $command = ($viaShell ? '' : escapeshellarg(PHP_BINARY).' ').escapeshellarg($script).' '.escapeshellarg('return 1;');
        $started = hrtime(true);
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $root);

        if (! is_resource($process)) {
            throw new RuntimeException('could not start a probe process');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $elapsed = Stats::elapsedMs($started);

        if ($exit !== 0) {
            throw new RuntimeException(trim((string) $stdout.' '.(string) $stderr));
        }

        return $elapsed;
    }

    /** @param  array<string, mixed>  $payload */
    private function emit(array $payload): void
    {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        ($this->writer)($json."\n");
    }

    private function emitError(string $id, string $message): int
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
        $this->emit([
            'status' => 'error',
            'duration_ms' => 0,
            'failures' => [['id' => $id, 'message' => $message]],
        ]);

        return 2;
    }

    public static function help(): string
    {
        return <<<'HELP'
Probe runs a throwaway PHP snippet in a warm Laravel worker and prints one JSON object.

Usage:
  vendor/bin/probe 'return 1 + 1;'
  vendor/bin/probe --file=snippet.php
  vendor/bin/probe --tool=get_failures
  vendor/bin/probe --tool=get_failures --id=1
  vendor/bin/probe --tool=rerun_failed
  vendor/bin/probe --tool=run_affected --paths=app/Models/User.php
  vendor/bin/probe --tool=run_affected --tests=tests/Feature/UserTest.php
  vendor/bin/probe --tool=probe_browser --url=http://127.0.0.1:8000 --contains=Laravel
  vendor/bin/probe --tool=mutate_check --file=app/Models/User.php --limit=8
  vendor/bin/probe --tool=property_check --property=addition_commutative --cases=20
  vendor/bin/probe --tool=architecture_check
  vendor/bin/probe --tool=formal_check --file=specs/auth.als
  vendor/bin/probe --tool=auto_probe --paths=app/Models/User.php
  vendor/bin/probe --tool=static_check --paths=app/Models/User.php
  vendor/bin/probe --tool=type_check
  vendor/bin/probe --tool=contract_check --routes='GET /api/users/{id}'
  vendor/bin/probe --status            (also --tool=status, --tool=cold, --tool=stop)
  vendor/bin/probe --stop
  vendor/bin/probe --cold 'return 1;'
  vendor/bin/probe --benchmark

Exit codes: 0 passed, 1 failed, 2 error or unsupported.

Snippets run inside the application. Put use imports on their own lines at the top.
A namespace declaration is rejected. Facade aliases and helpers such as app(), config(), and now() work.
Return a value to include it. Null returns are omitted so a green probe stays small.
The worker's default database is SQLite :memory: when pdo_sqlite is available, and each probe's writes roll back.

duration_ms is the time inside the worker, rounded to milliseconds.
vendor/bin/probe --benchmark reports the wall clock an agent pays, including a fresh PHP process.

This is a local development tool. It executes arbitrary PHP. Keep the worker socket on the same machine and out of production.

HELP;
    }
}
