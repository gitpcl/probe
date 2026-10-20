<?php

declare(strict_types=1);

namespace Probe\Tests;

use PHPUnit\Framework\TestCase;
use Probe\Browser\HybridBrowser;
use Probe\Selective\AffectedRunner;
use Probe\Selective\DependencyGraph;
use Probe\Selective\DurationBook;
use Probe\Selective\JunitReport;
use Probe\Selective\ShardPlanner;

final class PhaseTwoTest extends TestCase
{
    public function test_dependency_graph_selects_the_referencing_test(): void
    {
        $root = $this->tree([
            'app/Models/User.php' => "<?php\nnamespace App\\Models;\nclass User {}\n",
            'tests/Feature/UserTest.php' => "<?php\nuse App\\Models\\User;\nclass UserTest {}\n",
            'tests/Feature/OrderTest.php' => "<?php\nclass OrderTest {}\n",
        ]);

        $selected = (new DependencyGraph($root))->affectedTests(['app/Models/User.php']);
        $names = array_map('basename', $selected);

        $this->assertSame(['UserTest.php'], $names);
    }

    public function test_shard_planner_packs_long_files_apart(): void
    {
        $plan = (new ShardPlanner)->plan(
            ['a.php', 'b.php', 'c.php'],
            ['a.php' => 100, 'b.php' => 40, 'c.php' => 30],
            2,
        );

        $groups = array_map(static function (array $shard): array {
            sort($shard);

            return $shard;
        }, $plan);
        usort($groups, static fn (array $left, array $right): int => implode(',', $left) <=> implode(',', $right));

        $this->assertSame([['a.php'], ['b.php', 'c.php']], $groups);
    }

    public function test_junit_report_reads_a_failure_message(): void
    {
        $parsed = JunitReport::parse(<<<'XML'
<?xml version="1.0"?>
<testsuites>
  <testsuite tests="1" failures="1">
    <testcase name="test_it" classname="UserTest" file="/tmp/UserTest.php" time="0.02">
      <failure message="expected 1">expected 1</failure>
    </testcase>
  </testsuite>
</testsuites>
XML);

        $this->assertSame(1, $parsed['tests']);
        $this->assertSame('UserTest::test_it: expected 1', $parsed['failures'][0]['message']);
        $this->assertSame(20, $parsed['times']['/tmp/UserTest.php']);
    }

    public function test_run_affected_executes_only_the_impacted_test(): void
    {
        $root = $this->tree([
            'app/Models/User.php' => "<?php\nnamespace App\\Models;\nclass User {}\n",
            'tests/Feature/UserTest.php' => "<?php\nclass UserTest {}\n",
            'tests/Feature/OrderTest.php' => "<?php\nclass OrderTest {}\n",
        ]);
        $log = $root.'/ran.log';
        putenv('PROBE_STUB_LOG='.$log);
        putenv('PROBE_STUB_FAIL');
        putenv('PROBE_STUB_WATCH');

        try {
            $result = $this->runner($root, 1)->run(['paths' => ['app/Models/User.php']]);
        } finally {
            putenv('PROBE_STUB_LOG');
        }

        $this->assertSame('passed', $result->status, $result->toJson());
        $this->assertSame(1, $result->value['ran']);
        $this->assertStringContainsString('UserTest.php', (string) file_get_contents($log));
        $this->assertStringNotContainsString('OrderTest.php', (string) file_get_contents($log));
    }

    public function test_run_affected_failure_is_compact_json(): void
    {
        $root = $this->tree([
            'tests/Feature/FailTest.php' => "<?php\nclass FailTest {}\n",
        ]);

        $result = $this->runner($root, 1)->run(['paths' => ['tests/Feature/FailTest.php']]);

        $this->assertSame('failed', $result->status);
        $this->assertSame('expected 1', substr((string) strstr($result->failures[0]->message, 'expected'), 0));
        $this->assertArrayNotHasKey('trace', $result->toArray()['failures'][0]);
    }

    public function test_shards_run_across_the_worker_count(): void
    {
        $root = $this->tree([
            'tests/Feature/UserTest.php' => "<?php\nclass UserTest {}\n",
            'tests/Feature/OrderTest.php' => "<?php\nclass OrderTest {}\n",
        ]);
        $log = $root.'/ran.log';
        putenv('PROBE_STUB_LOG='.$log);

        try {
            $result = $this->runner($root, 2)->run([
                'tests' => [
                    $root.'/tests/Feature/UserTest.php',
                    $root.'/tests/Feature/OrderTest.php',
                ],
                'paths' => ['tests/Feature/UserTest.php'],
            ]);
        } finally {
            putenv('PROBE_STUB_LOG');
        }

        $this->assertSame('passed', $result->status, $result->toJson());
        $this->assertSame(2, $result->value['shards']);
        $logged = (string) file_get_contents($log);
        $this->assertStringContainsString('UserTest.php', $logged);
        $this->assertStringContainsString('OrderTest.php', $logged);
    }

    public function test_missing_runner_names_the_selected_tests(): void
    {
        $root = $this->tree([
            'tests/Feature/UserTest.php' => "<?php\nclass UserTest {}\n",
        ]);
        $runner = new AffectedRunner(
            $root,
            new DependencyGraph($root),
            new ShardPlanner,
            new DurationBook($root.'/durations.json'),
            1,
            $root.'/missing-phpunit',
        );

        $result = $runner->run(['paths' => ['tests/Feature/UserTest.php']]);

        $this->assertSame('error', $result->status);
        $this->assertSame('missing_tool', $result->failures[0]->id);
        $this->assertSame(['tests/Feature/UserTest.php'], $result->value['selected']);
    }

    public function test_lightpanda_dump_passes_and_a_missing_engine_is_an_error(): void
    {
        $dump = dirname(__DIR__).'/tests/Fixtures/browser-dump.php';
        $browser = new HybridBrowser([
            'browser' => [
                'engine' => 'auto',
                'lightpanda' => $dump,
                'playwright' => 'playwright-not-installed',
                'lightpanda_args' => [],
            ],
        ]);

        $passed = $browser->check([
            'url' => 'http://example.test',
            'contains' => 'Example Domain',
        ]);
        $this->assertSame('passed', $passed->status, $passed->toJson());
        $this->assertSame('lightpanda', $passed->value['engine']);
        $this->assertSame([], $passed->failures);

        $failed = $browser->check([
            'url' => 'http://example.test/missing-text',
            'contains' => 'Example Domain',
        ]);
        $this->assertSame('failed', $failed->status);
        $this->assertStringContainsString('missing text', $failed->failures[0]->message);

        $missing = (new HybridBrowser([
            'browser' => [
                'engine' => 'auto',
                'lightpanda' => 'lightpanda-not-installed',
                'playwright' => 'playwright-not-installed',
            ],
        ]))->check(['url' => 'http://example.test']);
        $this->assertSame('error', $missing->status);
        $this->assertSame('missing_tool', $missing->failures[0]->id);

        $rejected = $browser->check(['url' => 'file:///etc/passwd']);
        $this->assertSame('error', $rejected->status);
        $this->assertSame('url', $rejected->failures[0]->id);
    }

    public function test_auto_falls_back_to_playwright_for_steps(): void
    {
        $browser = new HybridBrowser([
            'browser' => [
                'engine' => 'auto',
                'lightpanda' => dirname(__DIR__).'/tests/Fixtures/browser-dump.php',
                'playwright' => dirname(__DIR__).'/tests/Fixtures/browser-playwright.php',
                'lightpanda_args' => [],
            ],
        ]);

        $result = $browser->check([
            'url' => 'http://example.test/login',
            'contains' => 'Example Domain',
            'steps' => [['action' => 'click', 'selector' => 'button']],
        ]);

        $this->assertSame('passed', $result->status, $result->toJson());
        $this->assertSame('playwright', $result->value['engine']);
    }

    private function runner(string $root, int $workers): AffectedRunner
    {
        return new AffectedRunner(
            $root,
            new DependencyGraph($root),
            new ShardPlanner,
            new DurationBook($root.'/durations.json'),
            $workers,
            dirname(__DIR__).'/tests/Fixtures/phpunit-stub.php',
        );
    }

    /** @param  array<string, string>  $files */
    private function tree(array $files): string
    {
        $root = sys_get_temp_dir().'/probe-phase2-'.bin2hex(random_bytes(4));
        foreach ($files as $path => $contents) {
            $absolute = $root.'/'.$path;
            $directory = dirname($absolute);
            if (! is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
            file_put_contents($absolute, $contents);
        }

        return $root;
    }
}
