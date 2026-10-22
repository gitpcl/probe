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

    public function test_phase_flags_are_parsed(): void
    {
        $invocation = Argv::parse([
            'probe',
            '--tool=probe_browser',
            '--url=http://example.test',
            '--contains=Example',
            '--engine=lightpanda',
            '--bridge=alloy',
            '--cases=12',
            '--seed=3',
            '--limit=4',
            '--property=addition_commutative',
        ]);

        $this->assertSame('probe_browser', $invocation->tool);
        $this->assertSame('http://example.test', $invocation->url);
        $this->assertSame('Example', $invocation->contains);
        $this->assertSame('lightpanda', $invocation->engine);
        $this->assertSame('alloy', $invocation->bridge);
        $this->assertSame(12, $invocation->cases);
        $this->assertSame(3, $invocation->seed);
        $this->assertSame(4, $invocation->limit);
        $this->assertSame('addition_commutative', $invocation->property);
    }

    public function test_an_unknown_option_is_a_usage_error_instead_of_snippet_code(): void
    {
        $typo = Argv::parse(['probe', '--tool=probe', '--code=$x = 1; return $x + 1;']);

        $this->assertSame('unknown option --code; snippet code goes last or after --, see --help', $typo->error);
        $this->assertNull($typo->code);

        $decrement = Argv::parse(['probe', '--', '--$x;']);

        $this->assertNull($decrement->error);
        $this->assertSame('--$x;', $decrement->code);
    }

    public function test_code_after_double_dash_is_preserved(): void
    {
        $invocation = Argv::parse(['probe', '--', '--tool is not a flag']);

        $this->assertSame('--tool is not a flag', $invocation->code);
        $this->assertSame('probe', $invocation->tool);
    }
}
