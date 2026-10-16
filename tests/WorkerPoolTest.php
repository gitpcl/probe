<?php

declare(strict_types=1);

namespace Probe\Tests;

use PHPUnit\Framework\TestCase;
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
        $this->pool = new Pool();
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
            'code' => 'throw new RuntimeException("boom");',
        ], 10000), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('failed', $failed['status']);
        $this->assertArrayNotHasKey('trace', $failed['failures'][0]);

        $detail = json_decode($this->pool->dispatch($this->root, [
            'tool' => 'get_failures',
        ], 10000), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('RuntimeException', $detail['failures'][0]['type']);
        $this->assertArrayHasKey('trace', $detail['failures'][0]);

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
