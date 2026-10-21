<?php

declare(strict_types=1);

namespace Probe\Tests;

use PHPUnit\Framework\TestCase;
use Probe\Probe;
use Probe\Result\Failure;
use Probe\Runner\DatabaseGuard;
use Probe\Runner\SnippetRunner;
use RuntimeException;

final class SnippetExecutorTest extends TestCase
{
    public function test_a_failure_trace_keeps_only_application_frames(): void
    {
        $result = (new SnippetRunner(new DatabaseGuard))->run('throw new RuntimeException("boom");');

        $trace = (string) $result->failures[0]->trace;
        $this->assertSame('failed', $result->status);
        $this->assertStringNotContainsString('SnippetRunner.php', $trace);
        $this->assertStringNotContainsString('execute_snippet.php', $trace);
        $this->assertStringNotContainsString('probe-snippet-', $trace);
        $this->assertStringStartsWith('#0 ', $trace);
    }

    public function test_return_value_and_missing_return(): void
    {
        $this->assertSame(2, probe_execute_snippet('return 1 + 1;'));
        $this->assertNull(probe_execute_snippet('1 + 1;'));
    }

    public function test_leading_use_import_resolves_inside_the_snippet(): void
    {
        $value = probe_execute_snippet("use Probe\\Probe;\n\nreturn Probe::VERSION;");

        $this->assertSame(Probe::VERSION, $value);
    }

    public function test_namespace_declaration_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        probe_execute_snippet("namespace App;\nreturn 1;");
    }

    public function test_failure_line_points_at_the_snippet(): void
    {
        try {
            probe_execute_snippet('throw new RuntimeException("say \"hi\"");');
            $this->fail('snippet should have thrown');
        } catch (RuntimeException $exception) {
            $failure = Failure::fromThrowable($exception);
        }

        $this->assertSame('probe.php', $failure->file);
        $this->assertSame(1, $failure->line);
        $this->assertSame('say "hi"', $failure->message);
        $this->assertSame(['id' => '1', 'message' => 'say "hi"'], $failure->compact());
    }

    public function test_a_probe_leaves_the_alarm_handler_as_it_found_it(): void
    {
        if (! function_exists('pcntl_signal_get_handler')) {
            $this->markTestSkipped('pcntl is not loaded');
        }

        $before = pcntl_signal_get_handler(SIGALRM);

        $result = (new SnippetRunner(new DatabaseGuard))->run('return 1;');

        $this->assertSame('passed', $result->status, $result->toJson());
        $this->assertSame($before, pcntl_signal_get_handler(SIGALRM));
    }

    public function test_a_probe_leaves_the_error_handler_as_it_found_it(): void
    {
        $sentinel = static fn (): bool => false;
        set_error_handler($sentinel);

        try {
            (new SnippetRunner(new DatabaseGuard))->run('return 1;');
            $active = set_error_handler(null);
            restore_error_handler();

            $this->assertSame($sentinel, $active);
        } finally {
            restore_error_handler();
        }
    }
}
