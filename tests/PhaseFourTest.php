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

    public function test_verdicts_pass_on_exit_zero_for_every_bridge(): void
    {
        foreach (['alloy', 'tla', 'dafny', 'lean'] as $bridge) {
            $verdict = Verdict::interpret($bridge, 0, "ok\n", '');
            $this->assertSame('passed', $verdict['status'], $bridge);
            $this->assertSame('', $verdict['trace'], $bridge);
        }
    }

    public function test_spec_runner_validates_the_bridge_and_the_file_before_running(): void
    {
        $root = $this->root();
        file_put_contents($root.'/specs/notes.txt', "not a spec\n");
        $outside = sys_get_temp_dir().'/probe-outside-'.bin2hex(random_bytes(4)).'.als';
        file_put_contents($outside, "sig User {}\n");
        $runner = new SpecRunner($root, ['formal' => ['alloy' => $this->stub()]]);

        $this->assertSame('bridge', $runner->run([])->failures[0]->id);
        $this->assertSame('bridge', $runner->run(['file' => 'specs/notes.txt'])->failures[0]->id);
        $unknown = $runner->run(['bridge' => 'nope', 'file' => 'specs/notes.txt']);
        $this->assertSame('bridge', $unknown->failures[0]->id);
        $this->assertSame('unknown bridge nope', $unknown->failures[0]->message);
        $this->assertSame('file', $runner->run(['bridge' => 'alloy'])->failures[0]->id);
        $this->assertSame('file', $runner->run(['file' => $outside])->failures[0]->id);
    }

    public function test_spec_runner_picks_the_bridge_from_the_extension_or_an_alias(): void
    {
        $root = $this->root();
        $stub = $this->stub();
        $runner = new SpecRunner($root, ['formal' => ['tla' => $stub, 'dafny' => $stub, 'lean' => $stub]]);

        putenv('PROBE_FORMAL_STUB=pass');
        try {
            foreach (['tla' => 'tla', 'dfy' => 'dafny', 'lean' => 'lean'] as $extension => $bridge) {
                file_put_contents($root.'/specs/spec.'.$extension, "spec\n");
                $result = $runner->run(['file' => 'specs/spec.'.$extension]);
                $this->assertSame('passed', $result->status, $result->toJson());
                $this->assertSame($bridge, $result->value['bridge']);
            }

            $alias = $runner->run(['bridge' => 'TLA+', 'file' => 'specs/spec.tla']);
            $this->assertSame('passed', $alias->status, $alias->toJson());
            $this->assertSame('tla', $alias->value['bridge']);
        } finally {
            putenv('PROBE_FORMAL_STUB');
        }
    }

    public function test_an_inline_spec_runs_from_a_temp_file_that_is_removed_afterwards(): void
    {
        $runner = new SpecRunner($this->root(), ['formal' => ['tla' => $this->stub()]]);
        $before = glob(sys_get_temp_dir().'/probe-spec-*') ?: [];

        putenv('PROBE_FORMAL_STUB=tla');
        try {
            $result = $runner->run(['bridge' => 'tla', 'code' => "---- MODULE Spec ----\n====\n"]);
        } finally {
            putenv('PROBE_FORMAL_STUB');
        }

        $this->assertSame('failed', $result->status, $result->toJson());
        $this->assertSame('tla', $result->failures[0]->type);
        $this->assertSame('tla.spec', $result->failures[0]->file);
        $this->assertSame('Invariant Inv is violated', $result->failures[0]->message);
        $this->assertSame($before, glob(sys_get_temp_dir().'/probe-spec-*') ?: []);
    }

    public function test_a_prover_that_hangs_is_a_timeout_error(): void
    {
        $root = $this->root();
        file_put_contents($root.'/specs/auth.als', "sig User {}\n");
        $runner = new SpecRunner($root, ['formal' => ['alloy' => $this->stub(), 'timeout_ms' => 200]]);

        putenv('PROBE_FORMAL_STUB=hang');
        try {
            $result = $runner->run(['file' => 'specs/auth.als']);
        } finally {
            putenv('PROBE_FORMAL_STUB');
        }

        $this->assertSame('error', $result->status, $result->toJson());
        $this->assertSame('timeout', $result->failures[0]->id);
        $this->assertLessThan(3000, $result->durationMs);
    }

    private function root(): string
    {
        $root = sys_get_temp_dir().'/probe-formal-'.bin2hex(random_bytes(4));
        mkdir($root.'/specs', 0775, true);

        return $root;
    }

    private function stub(): string
    {
        return dirname(__DIR__).'/tests/Fixtures/formal-stub.php';
    }
}
