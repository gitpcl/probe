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
        $selected = (new HeuristicSelector())->select(['app/Models/User.php'], [
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
            new ShardPlanner(),
            new DurationBook($root.'/durations.json'),
            1,
            dirname(__DIR__).'/tests/Fixtures/phpunit-stub.php',
        );
        $auto = new AutoProbe(
            $root,
            $graph,
            $runner,
            new SnippetRunner(new DatabaseGuard()),
            new HeuristicSelector(),
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
            new AffectedRunner($root, $graph, new ShardPlanner(), new DurationBook($root.'/durations.json'), 1, null),
            new SnippetRunner(new DatabaseGuard()),
            new HeuristicSelector(),
            new LlmSelector(false, null),
            new FailureHistory($root.'/failure-history.json'),
        );

        $result = $auto->run(['paths' => ['app/Widget.php']]);

        $this->assertSame('passed', $result->status, $result->toJson());
        $this->assertSame('heuristic', $result->value['selector']);
        $this->assertSame(1, $result->value['classes']);
        $this->assertSame(0, $result->value['ran']);
    }
}
