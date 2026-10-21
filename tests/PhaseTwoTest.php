<?php

declare(strict_types=1);

namespace Probe\Tests;

use PHPUnit\Framework\TestCase;
use Probe\Browser\HybridBrowser;
use Probe\Project;
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

    public function test_a_change_follows_static_references_to_the_tests_of_its_callers(): void
    {
        $root = $this->tree([
            'app/Services/Orchestrator.php' => "<?php\nnamespace App\\Services;\nclass Orchestrator {}\n",
            'app/Http/Controllers/VoiceController.php' => "<?php\nnamespace App\\Http\\Controllers;\n\nuse App\\Services\\Orchestrator;\n\nclass VoiceController {\n    public function process(): void { (new Orchestrator)->run(); }\n}\n",
            'app/Jobs/Unrelated.php' => "<?php\nnamespace App\\Jobs;\nclass Unrelated { public function handle(\\App\\Http\\Controllers\\VoiceController \$c): void {} }\n",
            'tests/Feature/OrchestratorTest.php' => "<?php\nuse App\\Services\\Orchestrator;\nclass OrchestratorTest {}\n",
            'tests/Feature/VoiceControllerTest.php' => "<?php\nuse App\\Http\\Controllers\\VoiceController;\nclass VoiceControllerTest {}\n",
            'tests/Feature/UnrelatedJobTest.php' => "<?php\nuse App\\Jobs\\Unrelated;\nclass UnrelatedJobTest {}\n",
            'tests/Feature/OtherTest.php' => "<?php\nclass OtherTest {}\n",
        ]);

        $names = fn (array $files): array => array_map('basename', $files);

        $this->assertSame(['OrchestratorTest.php'], $names((new DependencyGraph($root, 0))->affectedTests(['app/Services/Orchestrator.php'])));
        $this->assertSame(['OrchestratorTest.php', 'VoiceControllerTest.php'], $names((new DependencyGraph($root, 1))->affectedTests(['app/Services/Orchestrator.php'])));
        $this->assertSame(['OrchestratorTest.php', 'UnrelatedJobTest.php', 'VoiceControllerTest.php'], $names((new DependencyGraph($root, 4))->affectedTests(['app/Services/Orchestrator.php'])));
    }

    public function test_a_route_to_an_impacted_controller_selects_the_test_that_only_hits_the_uri(): void
    {
        $root = $this->tree([
            'app/Services/Orchestrator.php' => "<?php\nnamespace App\\Services;\nclass Orchestrator {}\n",
            'app/Http/Controllers/VoiceController.php' => "<?php\nnamespace App\\Http\\Controllers;\nuse App\\Services\\Orchestrator;\nclass VoiceController { public function process(Orchestrator \$o): void {} }\n",
            'tests/Feature/VoiceLoopStabilityTest.php' => "<?php\nclass VoiceLoopStabilityTest { public function test_it() { \$this->postJson('/_/voice/process', []); } }\n",
            'tests/Feature/NamedRouteTest.php' => "<?php\nclass NamedRouteTest { public function test_it() { \$this->get(route(\"voice.show\")); } }\n",
            'tests/Feature/OtherTest.php' => "<?php\nclass OtherTest { public function test_it() { \$this->get('/other'); } }\n",
        ]);
        $routes = static fn (): array => ['App\\Http\\Controllers\\VoiceController' => ['/_/voice/process', 'voice.show']];

        $selected = array_map('basename', (new DependencyGraph($root, 4, $routes))->affectedTests(['app/Services/Orchestrator.php']));

        $this->assertSame(['NamedRouteTest.php', 'VoiceLoopStabilityTest.php'], $selected);
        $this->assertSame([], (new DependencyGraph($root, 4))->affectedTests(['app/Services/Orchestrator.php']), 'without a route map the URI hop is off');
    }

    public function test_the_reference_index_follows_edits_to_source_files(): void
    {
        $root = $this->tree([
            'app/A.php' => "<?php\nnamespace App;\nclass A {}\n",
            'app/B.php' => "<?php\nnamespace App;\nclass B {}\n",
            'tests/Feature/BTest.php' => "<?php\nuse App\\B;\nclass BTest {}\n",
        ]);
        $graph = new DependencyGraph($root, 2);

        $this->assertSame([], $graph->affectedTests(['app/A.php']));

        file_put_contents($root.'/app/B.php', "<?php\nnamespace App;\nclass B { public function __construct(A \$a) {} }\n");
        touch($root.'/app/B.php', time() + 5);

        $this->assertSame(['BTest.php'], array_map('basename', $graph->affectedTests(['app/A.php'])));
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

    public function test_junit_times_are_per_file_even_when_pest_appends_the_test_name(): void
    {
        $parsed = JunitReport::parse(<<<'XML'
<?xml version="1.0"?>
<testsuites>
  <testsuite tests="3" failures="0">
    <testcase name="first" classname="UserTest" file="/tmp/UserTest.php::first" time="0.02"/>
    <testcase name="second" classname="UserTest" file="/tmp/UserTest.php::second" time="0.03"/>
    <testcase name="other" classname="OrderTest" file="/tmp/OrderTest.php" time="0.01"/>
  </testsuite>
</testsuites>
XML);

        $this->assertSame(['/tmp/UserTest.php' => 50, '/tmp/OrderTest.php' => 10], $parsed['times']);
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
        $this->assertSame('affected', $result->value['selection']);
        $this->assertSame(['tests/Feature/UserTest.php'], $result->value['selected']);
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

    public function test_a_short_suite_runs_in_full(): void
    {
        $root = $this->tree([
            'app/Models/User.php' => "<?php\nnamespace App\\Models;\nclass User {}\n",
            'tests/Feature/UserTest.php' => "<?php\nclass UserTest {}\n",
            'tests/Feature/OrderTest.php' => "<?php\nclass OrderTest {}\n",
        ]);
        $log = $root.'/ran.log';
        putenv('PROBE_STUB_LOG='.$log);

        try {
            $result = $this->runner($root, 1, 15000)->run(['paths' => ['app/Models/User.php']]);
        } finally {
            putenv('PROBE_STUB_LOG');
        }

        $this->assertSame('passed', $result->status, $result->toJson());
        $this->assertSame('all', $result->value['selection']);
        $this->assertSame(['tests/Feature/OrderTest.php', 'tests/Feature/UserTest.php'], $result->value['selected']);
        $this->assertStringContainsString('OrderTest.php', (string) file_get_contents($log));
    }

    public function test_a_long_suite_keeps_the_graph_selection(): void
    {
        $root = $this->tree([
            'app/Models/User.php' => "<?php\nnamespace App\\Models;\nclass User {}\n",
            'tests/Feature/UserTest.php' => "<?php\nclass UserTest {}\n",
            'tests/Feature/OrderTest.php' => "<?php\nclass OrderTest {}\n",
        ]);
        $real = realpath($root) ?: $root;
        file_put_contents($root.'/durations.json', json_encode([
            $real.'/tests/Feature/UserTest.php' => 9000,
            $real.'/tests/Feature/OrderTest.php' => 9000,
        ]));

        $result = $this->runner($root, 1, 15000)->run(['paths' => ['app/Models/User.php']]);

        $this->assertSame('passed', $result->status, $result->toJson());
        $this->assertSame('affected', $result->value['selection']);
        $this->assertSame(['tests/Feature/UserTest.php'], $result->value['selected']);
    }

    public function test_fork_mode_runs_shards_in_forked_children_and_reports_it(): void
    {
        $root = $this->tree([
            'tests/Feature/UserTest.php' => "<?php\nclass UserTest {}\n",
            'tests/Feature/OrderTest.php' => "<?php\nclass OrderTest {}\n",
        ]);
        $log = $root.'/ran.log';
        putenv('PROBE_STUB_LOG='.$log);

        try {
            $result = $this->runner($root, 2)->run(['tests' => [$root.'/tests/Feature/UserTest.php', $root.'/tests/Feature/OrderTest.php'], 'paths' => ['tests']]);
        } finally {
            putenv('PROBE_STUB_LOG');
        }

        $this->assertSame('passed', $result->status, $result->toJson());
        $this->assertSame('fork', $result->value['mode']);
        $this->assertSame(2, $result->value['shards']);
        $this->assertGreaterThanOrEqual($result->durationMs, $result->value['shard_ms'], 'shards run side by side: their sum is at least the wall time');
        $this->assertSame(2, $result->value['ran']);
        $logged = (string) file_get_contents($log);
        $this->assertStringContainsString('UserTest.php', $logged);
        $this->assertStringContainsString('OrderTest.php', $logged);
    }

    public function test_process_mode_starts_a_runner_process_per_shard(): void
    {
        $root = $this->tree(['tests/Feature/UserTest.php' => "<?php\nclass UserTest {}\n"]);

        $result = $this->runner($root, 1, 0, 'process')->run(['paths' => ['tests/Feature/UserTest.php']]);

        $this->assertSame('passed', $result->status, $result->toJson());
        $this->assertSame('process', $result->value['mode']);
    }

    public function test_a_hung_forked_shard_is_killed_at_the_timeout(): void
    {
        $root = $this->tree(['tests/Feature/UserTest.php' => "<?php\nclass UserTest {}\n"]);
        putenv('PROBE_STUB_SLEEP_MS=3000');
        $started = hrtime(true);

        try {
            $result = $this->runner($root, 1, 0, 'fork', 400)->run(['paths' => ['tests/Feature/UserTest.php']]);
        } finally {
            putenv('PROBE_STUB_SLEEP_MS');
        }

        $this->assertSame('error', $result->status, $result->toJson());
        $this->assertSame('test shard timed out', $result->failures[0]->message);
        $this->assertLessThan(2500, (hrtime(true) - $started) / 1e6);
    }

    public function test_auto_shards_follow_the_cpu_count_capped_by_the_file_count(): void
    {
        $root = $this->tree([
            'tests/Feature/ATest.php' => "<?php\nclass ATest {}\n",
            'tests/Feature/BTest.php' => "<?php\nclass BTest {}\n",
            'tests/Feature/CTest.php' => "<?php\nclass CTest {}\n",
        ]);

        $result = $this->runner($root, 0, 15000)->run(['paths' => ['tests']]);

        $this->assertSame('passed', $result->status, $result->toJson());
        $this->assertSame(min(3, Project::cpuCount()), $result->value['shards']);
    }

    public function test_a_persistent_browser_answers_from_one_process_and_recovers_from_death_and_timeout(): void
    {
        $browser = new HybridBrowser([
            'browser' => [
                'engine' => 'auto',
                'persistent' => true,
                'server' => dirname(__DIR__).'/tests/Fixtures/browser-server.php',
                'lightpanda' => 'lightpanda-not-installed',
                'base_url' => 'http://example.test',
                'timeout_ms' => 1000,
            ],
        ], '');

        try {
            $first = $browser->check(['url' => 'http://example.test/a', 'contains' => 'Example Domain']);
            $second = $browser->check(['url' => 'http://example.test/b', 'http_status' => 200]);
            $this->assertSame('passed', $first->status, $first->toJson());
            $this->assertSame('playwright', $first->value['engine']);
            $this->assertTrue($first->value['persistent']);
            $this->assertSame($first->value['pid'], $second->value['pid'], 'one browser process serves both checks');

            $died = $browser->check(['url' => 'http://example.test/die']);
            $this->assertSame('error', $died->status, $died->toJson());
            $revived = $browser->check(['url' => 'http://example.test/c']);
            $this->assertSame('passed', $revived->status, $revived->toJson());
            $this->assertNotSame($first->value['pid'], $revived->value['pid'], 'a dead browser is replaced on the next check');

            $slow = $browser->check(['url' => 'http://example.test/slow']);
            $this->assertSame('error', $slow->status, $slow->toJson());
            $this->assertSame('timeout', $slow->failures[0]->id);

            $notFound = $browser->check(['url' => 'http://example.test/nope', 'http_status' => 200]);
            $this->assertSame('failed', $notFound->status);
        } finally {
            $browser->stop();
        }
    }

    public function test_browser_errors_keep_the_engine_and_lose_ansi_codes(): void
    {
        $browser = new HybridBrowser([
            'browser' => [
                'engine' => 'auto',
                'persistent' => true,
                'server' => dirname(__DIR__).'/tests/Fixtures/browser-server.php',
                'lightpanda' => 'lightpanda-not-installed',
                'base_url' => 'http://example.test',
                'timeout_ms' => 1000,
            ],
        ], '');

        try {
            $result = $browser->check(['url' => 'http://example.test/ansi']);
        } finally {
            $browser->stop();
        }

        $this->assertSame('error', $result->status, $result->toJson());
        $this->assertSame('page.click: Timeout 8000ms exceeded. Call log: - waiting for locator', $result->failures[0]->message);
        $this->assertSame(['engine' => 'playwright', 'persistent' => true], $result->value);
    }

    public function test_persistent_off_keeps_the_one_shot_engines(): void
    {
        $browser = new HybridBrowser([
            'browser' => [
                'engine' => 'auto',
                'persistent' => false,
                'server' => dirname(__DIR__).'/tests/Fixtures/browser-server.php',
                'lightpanda' => dirname(__DIR__).'/tests/Fixtures/browser-dump.php',
                'base_url' => 'http://example.test',
                'timeout_ms' => 1000,
            ],
        ], '');

        $result = $browser->check(['url' => 'http://example.test/']);

        $this->assertSame('passed', $result->status, $result->toJson());
        $this->assertSame('lightpanda', $result->value['engine']);
        $this->assertArrayNotHasKey('persistent', $result->value);
    }

    public function test_a_status_assertion_is_unsupported_when_the_engine_cannot_report_one(): void
    {
        $browser = new HybridBrowser([
            'browser' => [
                'engine' => 'lightpanda',
                'lightpanda' => dirname(__DIR__).'/tests/Fixtures/browser-dump.php',
                'node' => 'node-not-installed',
                'base_url' => 'http://example.test',
                'timeout_ms' => 8000,
            ],
        ], '');

        $result = $browser->check(['url' => 'http://example.test/nope', 'http_status' => 200]);

        $this->assertSame('unsupported', $result->status, $result->toJson());
        $this->assertSame('http_status', $result->failures[0]->id);
        $this->assertSame('lightpanda', $result->value['engine']);
    }

    private function runner(string $root, int $workers, int $fullSuiteUnderMs = 0, string $mode = 'fork', int $timeoutMs = 120000): AffectedRunner
    {
        return new AffectedRunner(
            $root,
            new DependencyGraph($root),
            new ShardPlanner,
            new DurationBook($root.'/durations.json'),
            $workers,
            dirname(__DIR__).'/tests/Fixtures/phpunit-stub.php',
            $timeoutMs,
            $fullSuiteUnderMs,
            $mode,
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
