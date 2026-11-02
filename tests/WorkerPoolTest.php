<?php

declare(strict_types=1);

namespace Probe\Tests;

use PHPUnit\Framework\TestCase;
use Probe\Probe;
use Probe\Project;
use Probe\Support\Stats;
use Probe\Worker\Pool;

final class WorkerPoolTest extends TestCase
{
    private string $root = '';

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
        parent::tearDown();
    }

    public function test_warm_worker_returns_compact_json_under_the_latency_target(): void
    {
        $first = json_decode($this->pool->dispatch($this->root, [
            'tool' => 'probe',
            'code' => 'return 1 + 1;',
        ], 15000), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('passed', $first['status']);
        $this->assertSame(2, $first['value']);
        $this->assertSame([], $first['failures']);

        $samples = [];
        for ($index = 0; $index < 8; $index++) {
            $started = hrtime(true);
            $warm = json_decode($this->pool->dispatch($this->root, [
                'tool' => 'probe',
                'code' => 'return 1;',
            ], 10000), true, 512, JSON_THROW_ON_ERROR);
            $samples[] = Stats::elapsedMs($started);
            $this->assertSame('passed', $warm['status']);
            $this->assertSame(1, $warm['value']);
            $this->assertArrayNotHasKey('trace', $warm);
        }

        $median = Stats::median($samples);
        $this->assertLessThan(
            150,
            $median,
            'warm median was '.$median.' ms ('.implode(',', $samples).')'
        );
    }

    public function test_code_loaded_inside_a_probe_is_reloaded_after_an_edit(): void
    {
        $scratch = $this->root.'/ScratchInside.php';
        $code = 'require_once '.var_export($scratch, true).'; return probe_scratch_inside();';
        file_put_contents($scratch, "<?php\nfunction probe_scratch_inside(): string { return 'v1'; }\n");
        touch($scratch, time() - 10);

        try {
            $first = $this->probe($code);
            $this->assertSame('v1', $first['value'] ?? null, json_encode($first));

            file_put_contents($scratch, "<?php\nfunction probe_scratch_inside(): string { return 'v2'; }\n");
            touch($scratch, time() + 5);
            $second = $this->probe($code);

            $this->assertSame('passed', $second['status'], json_encode($second));
            $this->assertSame('v2', $second['value']);
        } finally {
            @unlink($scratch);
        }
    }

    public function test_code_loaded_at_boot_recycles_the_worker_after_an_edit(): void
    {
        $scratch = $this->root.'/ScratchBoot.php';
        file_put_contents($scratch, "<?php\nfunction probe_scratch_boot(): string { return 'v1'; }\n");
        touch($scratch, time() - 10);
        putenv('PROBE_BOOT_INCLUDE='.$scratch);

        try {
            $first = $this->probe('return probe_scratch_boot();');
            $this->assertSame('v1', $first['value'] ?? null, json_encode($first));

            file_put_contents($scratch, "<?php\nfunction probe_scratch_boot(): string { return 'v2'; }\n");
            touch($scratch, time() + 5);
            $second = $this->probe('return probe_scratch_boot();');

            $this->assertSame('passed', $second['status'], json_encode($second));
            $this->assertSame('v2', $second['value']);
            $this->assertStringEndsWith('ScratchBoot.php', $second['recycled']);
        } finally {
            putenv('PROBE_BOOT_INCLUDE');
            $this->pool->stop($this->root);
            @unlink($scratch);
        }
    }

    public function test_a_probe_that_exits_does_not_take_the_worker_down(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_getppid')) {
            $this->markTestSkipped('fork isolation needs pcntl and posix');
        }

        $before = $this->probe('return posix_getppid();');
        $this->assertSame('passed', $before['status'], json_encode($before));

        $exited = $this->probe('exit(3);');
        $this->assertSame('failed', $exited['status'], json_encode($exited));
        $this->assertSame('exit', $exited['failures'][0]['id']);

        $after = $this->probe('return posix_getppid();');
        $this->assertSame($before['value'], $after['value'], 'the worker outlived the exit');

        $ping = json_decode($this->pool->dispatch($this->root, ['tool' => 'ping'], 10000), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('fork', $ping['isolation']);
    }

    /** @return array<string, mixed> */
    private function probe(string $code): array
    {
        return json_decode($this->pool->dispatch($this->root, ['tool' => 'probe', 'code' => $code], 15000), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_the_shell_entry_answers_a_bare_snippet_from_the_live_worker(): void
    {
        $this->probe('return 1;');

        [$seven, $code] = $this->shellProbe('return 7;');
        $this->assertSame(0, $code, json_encode($seven));
        $this->assertSame(7, $seven['value']);

        [$thrown, $code] = $this->shellProbe('throw new RuntimeException("boom");');
        $this->assertSame(1, $code, json_encode($thrown));
        $this->assertSame('boom', $thrown['failures'][0]['message']);

        $snippet = "\$x = 'line1\nline2';\n\treturn [\$x, \"q\\\"q\", 'b\\\\s', '%s', 'ünï'];";
        [$escaped, $code] = $this->shellProbe($snippet);
        $this->assertSame(0, $code, json_encode($escaped));
        $this->assertSame(["line1\nline2", 'q"q', 'b\\s', '%s', 'ünï'], $escaped['value']);
    }

    public function test_the_shell_entry_falls_back_to_the_php_client(): void
    {
        $this->pool->stop($this->root);

        [$first, $code] = $this->shellProbe('return 3;');
        $this->assertSame(0, $code, json_encode($first));
        $this->assertSame(3, $first['value'], 'with no worker the PHP client spawns one');

        exec('sh '.escapeshellarg(dirname(__DIR__).'/bin/probe').' --version', $lines, $code);
        $this->assertSame(0, $code);
        $this->assertSame(Probe::VERSION, trim(implode('', $lines)));
    }

    /** @return array{0: array<string, mixed>, 1: int} */
    private function shellProbe(string ...$args): array
    {
        $command = 'cd '.escapeshellarg($this->root).' && sh '.escapeshellarg(dirname(__DIR__).'/bin/probe');
        foreach ($args as $arg) {
            $command .= ' '.escapeshellarg($arg);
        }

        exec($command.' 2>/dev/null', $lines, $code);
        $decoded = json_decode(implode("\n", $lines), true);

        return [is_array($decoded) ? $decoded : ['raw' => implode("\n", $lines)], $code];
    }

    public function test_worker_uses_sqlite_memory_and_rolls_back_writes(): void
    {
        $created = json_decode($this->pool->dispatch($this->root, [
            'tool' => 'probe',
            'code' => 'return config(\'database.default\');',
        ], 15000), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('passed', $created['status'], json_encode($created));
        $this->assertSame('probe', $created['value']);

        $inserted = json_decode($this->pool->dispatch($this->root, [
            'tool' => 'probe',
            'code' => 'Illuminate\Support\Facades\DB::table("notes")->insert(["body" => "hi"]); return Illuminate\Support\Facades\DB::table("notes")->count();',
        ], 10000), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('passed', $inserted['status'], json_encode($inserted));
        $this->assertSame(1, $inserted['value']);

        $after = json_decode($this->pool->dispatch($this->root, [
            'tool' => 'probe',
            'code' => 'return Illuminate\Support\Facades\DB::table("notes")->count();',
        ], 10000), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $after['value']);
    }

    public function test_failure_rerun_and_unsupported_tool_round_trip(): void
    {
        $failed = json_decode($this->pool->dispatch($this->root, [
            'tool' => 'probe',
            'code' => 'return collect([1])->map(fn (int $x): int => throw new RuntimeException("boom"))->all();',
        ], 10000), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('failed', $failed['status']);
        $this->assertArrayNotHasKey('trace', $failed['failures'][0]);

        $detail = json_decode($this->pool->dispatch($this->root, [
            'tool' => 'get_failures',
        ], 10000), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('RuntimeException', $detail['failures'][0]['type']);
        $this->assertArrayHasKey('trace', $detail['failures'][0]);
        $this->assertStringContainsString('Illuminate/Collections', $detail['failures'][0]['trace']);
        $this->assertStringNotContainsString('WorkCommand', $detail['failures'][0]['trace'], 'the worker stack below the snippet is not the application');

        $rerun = json_decode($this->pool->dispatch($this->root, [
            'tool' => 'rerun_failed',
        ], 10000), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('failed', $rerun['status']);

        $passed = json_decode($this->pool->dispatch($this->root, [
            'tool' => 'probe',
            'code' => 'return 1;',
        ], 10000), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('passed', $passed['status']);

        $rerunPassed = json_decode($this->pool->dispatch($this->root, [
            'tool' => 'rerun_failed',
        ], 10000), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($rerunPassed['rerun']);

        $affected = json_decode($this->pool->dispatch($this->root, [
            'tool' => 'run_affected',
            'paths' => ['app/Models/User.php'],
        ], 10000), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('passed', $affected['status'], json_encode($affected));
        $this->assertSame([], $affected['failures']);
        $this->assertSame(0, $affected['value']['ran']);

        $status = $this->pool->status($this->root);
        $this->assertSame('running', $status['status']);
        $this->assertNotSame('', Project::activeFile($this->root));
    }
}
