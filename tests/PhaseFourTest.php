<?php

declare(strict_types=1);

namespace Probe\Tests;

use PHPUnit\Framework\TestCase;
use Probe\Formal\SpecRunner;
use Probe\Formal\Verdict;

final class PhaseFourTest extends TestCase
{
    public function test_verdicts_turn_counterexamples_into_failures(): void
    {
        $alloy = Verdict::interpret('alloy', 1, "Counterexample found. Instance:\nUser.email = none\n", '');
        $this->assertSame('failed', $alloy['status']);
        $this->assertSame('Counterexample found', $alloy['message']);
        $this->assertStringContainsString('User.email = none', $alloy['trace']);

        $pass = Verdict::interpret('alloy', 0, "No counterexample found.\n", '');
        $this->assertSame('passed', $pass['status']);

        $tla = Verdict::interpret('tla', 1, "Error: Invariant Inv is violated.\nState 1: x = 0\n", '');
        $this->assertSame('failed', $tla['status']);
        $this->assertStringContainsString('Invariant Inv is violated', $tla['message']);

        $dafny = Verdict::interpret('dafny', 1, 'Dafny program verifier finished with 1 verified, 2 errors', '');
        $this->assertSame('failed', $dafny['status']);
        $this->assertStringContainsString('2 error', $dafny['message']);

        $lean = Verdict::interpret('lean', 1, 'spec.lean:3:0: error: unknown identifier nope', '');
        $this->assertSame('failed', $lean['status']);
        $this->assertStringContainsString('error:', $lean['message']);
    }

    public function test_missing_prover_is_an_error_and_a_stub_counterexample_is_a_failure(): void
    {
        $root = sys_get_temp_dir().'/probe-formal-'.bin2hex(random_bytes(4));
        mkdir($root.'/specs', 0775, true);
        file_put_contents($root.'/specs/auth.als', "sig User {}\n");
        $stub = dirname(__DIR__).'/tests/Fixtures/formal-stub.php';

        $missing = (new SpecRunner($root, ['formal' => ['alloy' => '/no/such/alloy']]))->run([
            'file' => 'specs/auth.als',
        ]);
        $this->assertSame('error', $missing->status);
        $this->assertSame('missing_tool', $missing->failures[0]->id);

        putenv('PROBE_FORMAL_STUB=pass');
        $passed = (new SpecRunner($root, ['formal' => ['alloy' => $stub]]))->run(['file' => 'specs/auth.als']);
        $this->assertSame('passed', $passed->status, $passed->toJson());
        $this->assertSame('alloy', $passed->value['bridge']);

        putenv('PROBE_FORMAL_STUB=counter');
        $failed = (new SpecRunner($root, ['formal' => ['alloy' => $stub]]))->run(['bridge' => 'alloy', 'file' => 'specs/auth.als']);
        putenv('PROBE_FORMAL_STUB');

        $this->assertSame('failed', $failed->status, $failed->toJson());
        $this->assertSame('Counterexample found', $failed->failures[0]->message);
        $this->assertSame('alloy', $failed->failures[0]->type);
        $this->assertStringContainsString('User.email = none', (string) $failed->failures[0]->trace);
        $this->assertArrayNotHasKey('trace', $failed->toArray()['failures'][0]);
        $this->assertStringContainsString('User.email = none', $failed->toArray(true)['failures'][0]['trace']);
    }
}
