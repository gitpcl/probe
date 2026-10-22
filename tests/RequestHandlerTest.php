<?php

declare(strict_types=1);

namespace Probe\Tests;

use Probe\Phase\Toolbox;
use Probe\Result\JsonResultEncoder;
use Probe\Runner\DatabaseGuard;
use Probe\Runner\RequestHandler;
use Probe\Runner\SnippetRunner;
use Probe\Session\SessionStore;
use Probe\Worker\SourceWatch;

final class RequestHandlerTest extends TestCase
{
    public function test_probe_failure_detail_and_rerun(): void
    {
        $handler = $this->handler();

        $failed = $handler->handle([
            'tool' => 'probe',
            'code' => 'throw new RuntimeException("boom");',
        ]);

        $this->assertSame('failed', $failed['status']);
        $this->assertSame('boom', $failed['failures'][0]['message']);
        $this->assertArrayNotHasKey('trace', $failed['failures'][0]);

        $detail = $handler->handle(['tool' => 'get_failures']);
        $this->assertSame('failed', $detail['status']);
        $this->assertSame('RuntimeException', $detail['failures'][0]['type']);
        $this->assertSame('probe.php', $detail['failures'][0]['file']);
        $this->assertArrayHasKey('trace', $detail['failures'][0]);

        $rerun = $handler->handle(['tool' => 'rerun_failed']);
        $this->assertSame('failed', $rerun['status']);
        $this->assertSame('boom', $rerun['failures'][0]['message']);
    }

    public function test_rerun_without_a_failure_is_a_small_pass(): void
    {
        $handler = $this->handler();
        $handler->handle(['tool' => 'probe', 'code' => 'return 1;']);

        $rerun = $handler->handle(['tool' => 'rerun_failed']);

        $this->assertSame(
            ['status' => 'passed', 'duration_ms' => 0, 'failures' => [], 'rerun' => false],
            $rerun
        );
    }

    public function test_phase_tools_return_real_errors_instead_of_stubs(): void
    {
        $root = sys_get_temp_dir().'/probe-handler-root-'.bin2hex(random_bytes(4));
        mkdir($root, 0775, true);
        $handler = $this->handler(new Toolbox($root, [
            'browser' => ['engine' => 'auto', 'lightpanda' => 'lightpanda-not-installed', 'playwright' => 'playwright-not-installed'],
            'formal' => ['alloy' => '/no/such/alloy'],
            'intelligence' => ['llm' => false],
        ], new SnippetRunner(new DatabaseGuard), $this->home()));

        $affected = $handler->handle(['tool' => 'run_affected']);
        $this->assertSame('error', $affected['status']);
        $this->assertSame('paths', $affected['failures'][0]['id']);

        $browser = $handler->handle(['tool' => 'probe_browser', 'url' => 'http://example.test']);
        $this->assertSame('error', $browser['status']);
        $this->assertSame('missing_tool', $browser['failures'][0]['id']);

        $formal = $handler->handle(['tool' => 'formal_check', 'bridge' => 'alloy']);
        $this->assertSame('error', $formal['status']);
        $this->assertContains($formal['failures'][0]['id'], ['file', 'missing_tool']);

        $property = $handler->handle([
            'tool' => 'property_check',
            'property' => 'addition_commutative',
            'cases' => 4,
            'seed' => 1,
        ]);
        $this->assertSame('passed', $property['status'], json_encode($property));
        $this->assertSame([], $property['failures']);

        $this->assertSame('error', $handler->handle(['tool' => 'nope'])['status']);
    }

    public function test_missing_code_is_an_error(): void
    {
        $result = $this->handler()->handle(['tool' => 'probe']);

        $this->assertSame('error', $result['status']);
        $this->assertSame('code', $result['failures'][0]['id']);
    }

    public function test_get_failures_shows_the_first_failure_and_the_total(): void
    {
        $session = new SessionStore($this->home().'/session.json');
        $session->put(['status' => 'failed', 'duration_ms' => 12, 'tool' => 'run_affected', 'failures' => [
            ['id' => '1', 'message' => 'first', 'type' => 'test'],
            ['id' => '2', 'message' => 'second', 'type' => 'test'],
        ]]);
        $handler = $this->handler(session: $session);

        $first = $handler->handle(['tool' => 'get_failures']);
        $this->assertCount(1, $first['failures']);
        $this->assertSame('first', $first['failures'][0]['message']);
        $this->assertSame(['shown' => 1, 'total' => 2], $first['value']);

        $second = $handler->handle(['tool' => 'get_failures', 'id' => '2']);
        $this->assertSame('second', $second['failures'][0]['message']);
        $this->assertSame(['shown' => 1, 'total' => 2], $second['value']);
    }

    public function test_a_changed_source_file_makes_the_worker_refuse_and_stop(): void
    {
        $root = sys_get_temp_dir().'/probe-handler-stale-'.bin2hex(random_bytes(4));
        mkdir($root.'/app', 0775, true);
        $thing = $root.'/app/Thing.php';
        file_put_contents($thing, "<?php\nreturn 'thing';\n");
        touch($thing, time() - 10);
        include $thing;
        $handler = $this->handler(watch: new SourceWatch($root));

        $this->assertArrayNotHasKey('stale', $handler->handle(['tool' => 'ping']));
        $this->assertSame('passed', $handler->handle(['tool' => 'probe', 'code' => 'return 1;'])['status']);

        touch($thing, time() + 5);
        $refused = $handler->handle(['tool' => 'probe', 'code' => 'return 1;']);

        $this->assertSame('error', $refused['status']);
        $this->assertSame('stale', $refused['failures'][0]['id']);
        $this->assertSame('app/Thing.php', $refused['stale']);
        $this->assertTrue($handler->shouldStop());
        $this->assertSame('app/Thing.php', $handler->handle(['tool' => 'ping'])['stale']);
    }

    public function test_a_run_result_lists_the_first_failures_and_the_session_keeps_them_all(): void
    {
        $root = sys_get_temp_dir().'/probe-handler-root-'.bin2hex(random_bytes(4));
        mkdir($root, 0775, true);
        file_put_contents($root.'/tsconfig.json', '{}');
        $lines = [];
        for ($index = 1; $index <= 25; $index++) {
            $lines[] = 'src/file'.$index.'.ts(1,1): error TS1000: problem '.$index;
        }
        putenv('PROBE_STUB_TS='.implode('\n', $lines));
        $handler = $this->handler(new Toolbox($root, [
            'types' => ['binary' => dirname(__DIR__).'/tests/Fixtures/tsc-stub.sh', 'timeout_ms' => 5000],
            'intelligence' => ['llm' => false],
        ], new SnippetRunner(new DatabaseGuard), $this->home()));

        try {
            $run = $handler->handle(['tool' => 'type_check']);
            $this->assertSame('failed', $run['status'], json_encode($run));
            $this->assertCount(20, $run['failures']);
            $this->assertSame(25, $run['failures_total']);
            $this->assertSame(25, $run['value']['errors']);

            $last = $handler->handle(['tool' => 'get_failures', 'id' => '25']);
            $this->assertSame('25', $last['failures'][0]['id']);
            $this->assertSame('src/file25.ts', $last['failures'][0]['file']);
            $this->assertSame(['shown' => 1, 'total' => 25], $last['value']);
            $this->assertArrayNotHasKey('failures_total', $last);

            $one = $handler->handle(['tool' => 'probe', 'code' => 'throw new RuntimeException("x");']);
            $this->assertCount(1, $one['failures']);
            $this->assertArrayNotHasKey('failures_total', $one, 'a short list is not annotated');
        } finally {
            putenv('PROBE_STUB_TS');
        }
    }

    private function handler(?Toolbox $toolbox = null, ?SourceWatch $watch = null, ?SessionStore $session = null): RequestHandler
    {
        $database = new DatabaseGuard;
        $database->boot($this->app);

        return new RequestHandler(
            new SnippetRunner($database, 1024, 5000),
            $session ?? new SessionStore($this->home().'/session.json'),
            new JsonResultEncoder,
            $database,
            100000,
            500,
            $toolbox,
            $watch,
        );
    }

    private function home(): string
    {
        $home = sys_get_temp_dir().'/probe-handler-'.bin2hex(random_bytes(4));
        mkdir($home, 0775, true);
        $this->beforeApplicationDestroyed(static function () use ($home): void {
            if (is_file($home.'/session.json')) {
                unlink($home.'/session.json');
            }
            if (is_file($home.'/session.json.lock')) {
                unlink($home.'/session.json.lock');
            }
            @rmdir($home);
        });

        return $home;
    }
}
