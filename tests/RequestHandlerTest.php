<?php

declare(strict_types=1);

namespace Probe\Tests;

use Probe\Result\JsonResultEncoder;
use Probe\Runner\DatabaseGuard;
use Probe\Runner\RequestHandler;
use Probe\Runner\SnippetRunner;
use Probe\Session\SessionStore;

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

    public function test_later_tools_are_unsupported(): void
    {
        $handler = $this->handler();

        $this->assertSame(2, $handler->handle(['tool' => 'run_affected', 'paths' => ['app/User.php']])['phase']);
        $this->assertSame(2, $handler->handle(['tool' => 'probe_browser'])['phase']);
        $this->assertSame(3, $handler->handle(['tool' => 'mutate_check'])['phase']);
        $this->assertSame(4, $handler->handle(['tool' => 'formal_check'])['phase']);
        $this->assertSame('error', $handler->handle(['tool' => 'nope'])['status']);
    }

    public function test_missing_code_is_an_error(): void
    {
        $result = $this->handler()->handle(['tool' => 'probe']);

        $this->assertSame('error', $result['status']);
        $this->assertSame('code', $result['failures'][0]['id']);
    }

    private function handler(): RequestHandler
    {
        $database = new DatabaseGuard();
        $database->boot($this->app);

        return new RequestHandler(
            new SnippetRunner($database, 1024, 5000),
            new SessionStore($this->home().'/session.json'),
            new JsonResultEncoder(),
            $database,
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
