<?php

declare(strict_types=1);

namespace Probe\Tests;

use Probe\Result\ProbeResult;
use Probe\Runner\DatabaseGuard;
use Probe\Runner\ForkedRun;
use Probe\Runner\SnippetRunner;
use Probe\Support\Stats;

final class ForkedRunTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! (new ForkedRun)->available()) {
            $this->markTestSkipped('fork isolation needs pcntl and posix');
        }
    }

    public function test_the_result_comes_back_from_the_child(): void
    {
        $parent = getmypid();

        $result = (new ForkedRun)->run(fn (): ProbeResult => ProbeResult::passed(3, ['pid' => getmypid()], true), 2000);

        $this->assertSame('passed', $result->status, $result->toJson());
        $this->assertNotSame($parent, $result->value['pid']);
        $this->assertSame($parent, getmypid());
    }

    public function test_an_abrupt_child_death_is_a_failure_and_the_parent_survives(): void
    {
        $result = (new ForkedRun)->run(function (): ProbeResult {
            posix_kill((int) getmypid(), SIGKILL);

            return ProbeResult::passed(0);
        }, 2000);

        $this->assertSame('failed', $result->status);
        $this->assertSame('exit', $result->failures[0]->id);
    }

    public function test_a_hung_child_is_killed_at_the_timeout(): void
    {
        $started = hrtime(true);

        $result = (new ForkedRun)->run(function (): ProbeResult {
            sleep(10);

            return ProbeResult::passed(0);
        }, 500);

        $this->assertSame('failed', $result->status);
        $this->assertSame('probe timed out', $result->failures[0]->message);
        $this->assertLessThan(2500, Stats::elapsedMs($started));
    }

    public function test_state_set_by_one_probe_does_not_reach_the_next(): void
    {
        $database = new DatabaseGuard;
        $database->boot($this->app);
        $runner = new SnippetRunner($database, 1024, 5000, new ForkedRun);

        $leak = $runner->run('config()->set("app.name", "LEAKED"); Illuminate\Support\Carbon::setTestNow("2020-01-01"); return config("app.name");');
        $this->assertSame('passed', $leak->status, $leak->toJson());
        $this->assertSame('LEAKED', $leak->value);

        $next = $runner->run('return [config("app.name"), Illuminate\Support\Carbon::hasTestNow()];');
        $this->assertSame('passed', $next->status, $next->toJson());
        $this->assertNotSame('LEAKED', $next->value[0]);
        $this->assertFalse($next->value[1]);
        $this->assertSame('fork', $runner->isolation());
    }

    public function test_a_disabled_fork_runs_in_process(): void
    {
        $runner = new SnippetRunner(new DatabaseGuard, 1024, 5000, new ForkedRun(false));

        $this->assertSame('process', $runner->isolation());
        $this->assertSame(getmypid(), $runner->run('return getmypid();')->value);
    }
}
