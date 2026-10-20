<?php

declare(strict_types=1);

namespace Probe\Tests;

use PHPUnit\Framework\TestCase;
use Probe\Intelligence\AutoProbe;
use Probe\Intelligence\FailureHistory;
use Probe\Intelligence\HeuristicSelector;
use Probe\Intelligence\LlmSelector;
use Probe\Runner\DatabaseGuard;
use Probe\Runner\SnippetRunner;
use Probe\Selective\AffectedRunner;
use Probe\Selective\DependencyGraph;
use Probe\Selective\DurationBook;
use Probe\Selective\ShardPlanner;

final class PhaseFiveTest extends TestCase
{
    public function test_heuristic_ranks_a_recent_failure_first(): void
    {
        $user = '/app/tests/Feature/UserTest.php';
        $order = '/app/tests/Feature/OrderTest.php';
        $selected = (new HeuristicSelector)->select(['app/Models/User.php'], [
            'tests' => [$order, $user],
            'history' => [$user],
            'limit' => 2,
        ]);

        $this->assertSame($user, $selected[0]);
    }

    public function test_llm_selector_stays_offline_unless_configured(): void
    {
        $selector = new LlmSelector(false, null);
        $this->assertFalse($selector->enabled());
        $this->assertSame([], $selector->select(['app/User.php'], ['tests' => ['tests/UserTest.php']]));
        $this->assertSame(['tests/UserTest.php'], $selector->parse('{"tests":["tests/UserTest.php"]}'));

        $down = new LlmSelector(true, 'http://127.0.0.1:9', null, 'test', 1000);
        $this->assertTrue($down->enabled());
        $this->assertSame([], $down->select(['app/User.php'], ['tests' => ['tests/UserTest.php']]));
    }

    public function test_auto_probe_runs_the_heuristic_selection(): void
    {
        $root = sys_get_temp_dir().'/probe-auto-'.bin2hex(random_bytes(4));
        mkdir($root.'/app/Models', 0775, true);
        mkdir($root.'/tests/Feature', 0775, true);
        file_put_contents($root.'/app/Models/User.php', "<?php\nnamespace App\\Models;\nclass User {}\n");
        file_put_contents($root.'/tests/Feature/UserTest.php', "<?php\nclass UserTest {}\n");

        $graph = new DependencyGraph($root);
        $runner = new AffectedRunner(
            $root,
            $graph,
            new ShardPlanner,
            new DurationBook($root.'/durations.json'),
            1,
            dirname(__DIR__).'/tests/Fixtures/phpunit-stub.php',
        );
        $auto = new AutoProbe(
            $root,
            $graph,
            $runner,
            new SnippetRunner(new DatabaseGuard),
            new HeuristicSelector,
            new LlmSelector(false, null),
            new FailureHistory($root.'/failure-history.json'),
            8,
        );

        $result = $auto->run(['paths' => ['app/Models/User.php']]);

        $this->assertSame('passed', $result->status, $result->toJson());
        $this->assertSame('heuristic', $result->value['selector']);
        $this->assertSame(1, $result->value['ran']);
    }

    public function test_auto_probe_smokes_a_class_when_no_test_matches(): void
    {
        $root = sys_get_temp_dir().'/probe-smoke-'.bin2hex(random_bytes(4));
        mkdir($root.'/app', 0775, true);
        file_put_contents($root.'/app/Widget.php', "<?php\nnamespace Probe\\Tests\\Fixtures;\nclass Widget {}\n");
        require_once $root.'/app/Widget.php';

        $graph = new DependencyGraph($root);
        $auto = new AutoProbe(
            $root,
            $graph,
            new AffectedRunner($root, $graph, new ShardPlanner, new DurationBook($root.'/durations.json'), 1, null),
            new SnippetRunner(new DatabaseGuard),
            new HeuristicSelector,
            new LlmSelector(false, null),
            new FailureHistory($root.'/failure-history.json'),
        );

        $result = $auto->run(['paths' => ['app/Widget.php']]);

        $this->assertSame('passed', $result->status, $result->toJson());
        $this->assertSame('heuristic', $result->value['selector']);
        $this->assertSame(1, $result->value['classes']);
        $this->assertSame(0, $result->value['ran']);
    }

    public function test_failure_history_round_trips_dedupes_and_caps_at_thirty(): void
    {
        $path = sys_get_temp_dir().'/probe-history-'.bin2hex(random_bytes(4)).'/failure-history.json';
        $history = new FailureHistory($path);

        $this->assertSame([], $history->recent());

        $history->push(['tests/A.php', 'tests/B.php', '']);
        $history->push(['tests/A.php', 'tests/C.php']);
        $this->assertSame(['tests/A.php', 'tests/B.php', 'tests/C.php'], $history->recent());

        $history->push(array_map(static fn (int $index): string => 'tests/T'.$index.'.php', range(1, 40)));
        $recent = $history->recent();
        $this->assertCount(30, $recent);
        $this->assertSame('tests/T40.php', end($recent));
        $this->assertNotContains('tests/A.php', $recent);

        file_put_contents($path, '{not json');
        $this->assertSame([], $history->recent());
    }

    public function test_heuristic_prefers_name_matches_and_respects_the_limit(): void
    {
        $selector = new HeuristicSelector;
        $order = '/app/tests/Feature/OrderTest.php';
        $invoice = '/app/tests/Feature/InvoiceTest.php';
        $user = '/app/tests/Unit/UserTest.php';
        $paths = ['app/Models/User.php'];

        $this->assertSame([$user, $order, $invoice], $selector->select($paths, ['tests' => [$order, $invoice, $user]]));
        $this->assertSame([$user, $order], $selector->select($paths, ['tests' => [$order, $invoice, $user], 'limit' => 2]));
        $this->assertSame([], $selector->select($paths, ['tests' => []]));
        $this->assertSame([$user], $selector->select($paths, ['tests' => [null, 42, '', $user]]));
    }

    public function test_llm_selector_parse_keeps_only_non_empty_strings(): void
    {
        $selector = new LlmSelector(false, null);

        $this->assertSame([], $selector->parse('not json'));
        $this->assertSame([], $selector->parse('{"tests":"tests/UserTest.php"}'));
        $this->assertSame(['tests/UserTest.php'], $selector->parse('{"tests":["tests/UserTest.php", 7, null, ""]}'));
        $this->assertSame(['tests/UserTest.php'], $selector->parse('{"selected":["tests/UserTest.php"]}'));
    }

    public function test_auto_probe_needs_paths_outside_a_git_repository(): void
    {
        $root = sys_get_temp_dir().'/probe-nopaths-'.bin2hex(random_bytes(4));
        mkdir($root, 0775, true);

        $result = $this->auto($root)->run([]);

        $this->assertSame('error', $result->status, $result->toJson());
        $this->assertSame('paths', $result->failures[0]->id);
    }

    public function test_auto_probe_records_a_failing_test_and_honours_the_limit(): void
    {
        $root = sys_get_temp_dir().'/probe-autofail-'.bin2hex(random_bytes(4));
        mkdir($root.'/app/Models', 0775, true);
        mkdir($root.'/tests/Feature', 0775, true);
        file_put_contents($root.'/app/Models/User.php', "<?php\nnamespace App\\Models;\nclass User {}\n");
        file_put_contents($root.'/tests/Feature/UserFailTest.php', "<?php\nclass UserFailTest {}\n");
        file_put_contents($root.'/tests/Feature/UserTest.php', "<?php\nclass UserTest {}\n");

        $result = $this->auto($root)->run(['paths' => ['app/Models/User.php'], 'limit' => 1]);

        $this->assertSame('failed', $result->status, $result->toJson());
        $this->assertSame(1, $result->value['ran']);
        $this->assertSame('heuristic', $result->value['selector']);
        $recorded = json_decode((string) file_get_contents($root.'/failure-history.json'), true);
        $this->assertCount(1, $recorded);
        $this->assertStringEndsWith('tests/Feature/UserFailTest.php', $recorded[0]);
    }

    public function test_auto_probe_reports_a_class_that_does_not_load(): void
    {
        $root = sys_get_temp_dir().'/probe-ghost-'.bin2hex(random_bytes(4));
        mkdir($root.'/app', 0775, true);
        file_put_contents($root.'/app/Ghost.php', "<?php\nnamespace Probe\\Tests\\Fixtures;\nclass Ghost {}\n");

        $result = $this->auto($root)->run(['paths' => ['app/Ghost.php']]);

        $this->assertSame('failed', $result->status, $result->toJson());
        $this->assertSame('missing Probe\\Tests\\Fixtures\\Ghost', $result->failures[0]->message);
    }

    private function auto(string $root): AutoProbe
    {
        $graph = new DependencyGraph($root);
        $runner = new AffectedRunner(
            $root,
            $graph,
            new ShardPlanner,
            new DurationBook($root.'/durations.json'),
            1,
            dirname(__DIR__).'/tests/Fixtures/phpunit-stub.php',
        );

        return new AutoProbe(
            $root,
            $graph,
            $runner,
            new SnippetRunner(new DatabaseGuard),
            new HeuristicSelector,
            new LlmSelector(false, null),
            new FailureHistory($root.'/failure-history.json'),
            8,
        );
    }
}
