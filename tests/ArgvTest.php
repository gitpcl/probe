<?php

declare(strict_types=1);

namespace Probe\Tests;

use PHPUnit\Framework\TestCase;
use Probe\Console\Argv;

final class ArgvTest extends TestCase
{
    public function test_positional_code_is_joined(): void
    {
        $invocation = Argv::parse(['probe', 'return', '1', '+', '1;']);

        $this->assertSame('probe', $invocation->tool);
        $this->assertSame('return 1 + 1;', $invocation->code);
        $this->assertFalse($invocation->cold);
    }

    public function test_flags_parse_with_equals_and_separated_values(): void
    {
        $invocation = Argv::parse([
            'probe',
            '--tool',
            'get_failures',
            '--id=1',
            '--timeout=2500',
            '--paths=app/User.php,app/Order.php',
            '--cold',
            '--benchmark',
            '--samples=7',
        ]);

        $this->assertSame('get_failures', $invocation->tool);
        $this->assertSame('1', $invocation->id);
        $this->assertSame(2500, $invocation->timeoutMs);
        $this->assertSame('app/User.php,app/Order.php', $invocation->paths);
        $this->assertTrue($invocation->cold);
        $this->assertTrue($invocation->benchmark);
        $this->assertSame(7, $invocation->samples);
    }

    public function test_code_after_double_dash_is_preserved(): void
    {
        $invocation = Argv::parse(['probe', '--', '--tool is not a flag']);

        $this->assertSame('--tool is not a flag', $invocation->code);
        $this->assertSame('probe', $invocation->tool);
    }
}
