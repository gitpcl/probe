<?php

declare(strict_types=1);

namespace Probe\Tests;

use PHPUnit\Framework\TestCase;
use Probe\Probe;
use Probe\Result\Failure;
use RuntimeException;

final class SnippetExecutorTest extends TestCase
{
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
}
