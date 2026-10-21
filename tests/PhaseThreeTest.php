<?php

declare(strict_types=1);

namespace Probe\Tests;

use PHPUnit\Framework\TestCase;
use Probe\Quality\ArchitectureCheck;
use Probe\Quality\MutationCheck;
use Probe\Quality\Mutator;
use Probe\Quality\PropertyCheck;
use Probe\Runner\DatabaseGuard;
use Probe\Runner\SnippetRunner;
use Probe\Selective\AffectedRunner;
use Probe\Selective\DependencyGraph;
use Probe\Selective\DurationBook;
use Probe\Selective\ShardPlanner;

final class PhaseThreeTest extends TestCase
{
    public function test_mutator_flips_a_single_operator(): void
    {
        $mutants = (new Mutator)->mutants("<?php\nreturn \$a + \$b;\n", 5);

        $this->assertNotEmpty($mutants);
        $this->assertSame('+', $mutants[0]['from']);
        $this->assertSame('-', $mutants[0]['to']);
        $this->assertStringContainsString(' - ', $mutants[0]['code']);
        $this->assertStringNotContainsString('+', $mutants[0]['code']);
    }

    public function test_killed_mutant_passes_and_a_survivor_is_a_failure(): void
    {
        $root = $this->tree([
            'src/Adder.php' => "<?php\nfunction add(\$a, \$b) { return \$a + \$b; }\n",
            'tests/Unit/AdderTest.php' => "<?php\nclass AdderTest {}\n",
        ]);
        putenv('PROBE_STUB_WATCH='.$root.'/src/Adder.php');
        putenv('PROBE_STUB_FAIL');

        try {
            $killed = $this->mutation($root)->run(['path' => 'src/Adder.php', 'limit' => 1]);
            $this->assertSame('passed', $killed->status, $killed->toJson());
            $this->assertSame(1, $killed->value['killed']);
            $this->assertSame(0, $killed->value['survived']);
            $this->assertStringContainsString('+', (string) file_get_contents($root.'/src/Adder.php'));
        } finally {
            putenv('PROBE_STUB_WATCH');
        }

        putenv('PROBE_STUB_FAIL');
        $survived = $this->mutation($root)->run(['path' => 'src/Adder.php', 'limit' => 1]);
        $this->assertSame('failed', $survived->status, $survived->toJson());
        $this->assertStringContainsString('survived + to -', $survived->failures[0]->message);
        $this->assertSame('mutation', $survived->failures[0]->type);
        $this->assertStringNotContainsString(' - ', (string) file_get_contents($root.'/src/Adder.php'));
    }

    public function test_property_presets_pass_and_a_false_property_fails(): void
    {
        $check = new PropertyCheck(new SnippetRunner(new DatabaseGuard), 8, 1);

        $passed = $check->run(['property' => 'addition_commutative', 'cases' => 8, 'seed' => 3]);
        $this->assertSame('passed', $passed->status, $passed->toJson());
        $this->assertSame(8, $passed->value['cases']);
        $this->assertSame([], $passed->failures);

        $failed = $check->run(['code' => 'return $input === "impossible-value";', 'cases' => 4, 'seed' => 1]);
        $this->assertSame('failed', $failed->status);
        $this->assertStringContainsString('property failed', $failed->failures[0]->message);
        $encoded = $failed->toJson();
        $this->assertStringNotContainsString('trace', $encoded);
    }

    public function test_architecture_rule_reports_a_forbidden_use(): void
    {
        $root = $this->tree([
            'app/Http/Controllers/UserController.php' => "<?php\nuse Illuminate\\Support\\Facades\\DB;\nclass UserController { public function index() { return DB::table('users'); } }\n",
        ]);

        $result = (new ArchitectureCheck($root, $this->runner($root), [], [
            ['path' => 'app/Http/Controllers', 'forbid_use' => ['Illuminate\\Support\\Facades\\DB']],
        ]))->run([]);

        $this->assertSame('failed', $result->status);
        $this->assertSame('architecture', $result->failures[0]->type);
        $this->assertStringContainsString('UserController.php depends on Illuminate\\Support\\Facades\\DB', $result->failures[0]->message);
    }

    public function test_architecture_without_rules_is_a_clear_error(): void
    {
        $root = $this->tree([]);
        $result = (new ArchitectureCheck($root, $this->runner($root), [], []))->run([]);

        $this->assertSame('error', $result->status);
        $this->assertSame('architecture', $result->failures[0]->id);
    }

    public function test_mutator_flips_comparisons_and_booleans_and_caps_the_count(): void
    {
        $mutator = new Mutator;

        $strict = $mutator->mutants("<?php\nreturn \$a === \$b;\n", 5);
        $this->assertSame(['===', '!=='], [$strict[0]['from'], $strict[0]['to']]);
        $this->assertStringContainsString('!==', $strict[0]['code']);

        $boolean = $mutator->mutants("<?php\nreturn true;\n", 5);
        $this->assertSame(['true', 'false'], [$boolean[0]['from'], $boolean[0]['to']]);

        $this->assertSame([], $mutator->mutants("<?php\n\$x = -1;\nreturn \$x;\n", 5));
        $this->assertSame([], $mutator->mutants("<?php\nreturn \$a;\n", 5));
        $this->assertCount(2, $mutator->mutants("<?php\nreturn \$a + \$b - \$c * \$d;\n", 2));
    }

    public function test_mutation_check_explains_each_reason_it_cannot_run(): void
    {
        $root = $this->tree([
            'src/Plain.php' => "<?php\nreturn 1;\n",
            'src/Lonely.php' => "<?php\nfunction lonely(\$a, \$b) { return \$a + \$b; }\n",
            'vendor/pkg/Dep.php' => "<?php\nreturn 1 + 1;\n",
            'src/Adder.php' => "<?php\nfunction add(\$a, \$b) { return \$a + \$b; }\n",
            'tests/Unit/AdderTest.php' => "<?php\nclass AdderTest {}\n",
        ]);
        $outside = sys_get_temp_dir().'/probe-outside-'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($outside, "<?php\nreturn 1 + 1;\n");
        $check = $this->mutation($root);

        $this->assertSame('path', $check->run([])->failures[0]->id);
        $this->assertSame('path', $check->run(['path' => 'src/Missing.php'])->failures[0]->id);
        $this->assertSame('path', $check->run(['path' => $outside])->failures[0]->id);
        $this->assertSame('path', $check->run(['path' => 'vendor/pkg/Dep.php'])->failures[0]->id);
        $this->assertSame('mutate', $check->run(['path' => 'src/Plain.php'])->failures[0]->id);
        $this->assertSame('tests', $check->run(['path' => 'src/Lonely.php'])->failures[0]->id);

        putenv('PROBE_STUB_FAIL=1');
        try {
            $noisy = $check->run(['path' => 'src/Adder.php', 'limit' => 1]);
        } finally {
            putenv('PROBE_STUB_FAIL');
        }

        $this->assertSame('error', $noisy->status, $noisy->toJson());
        $this->assertSame('tests', $noisy->failures[0]->id);
        $this->assertStringContainsString('already fail', $noisy->failures[0]->message);
        $this->assertSame("<?php\nfunction add(\$a, \$b) { return \$a + \$b; }\n", file_get_contents($root.'/src/Adder.php'));
    }

    public function test_mutation_check_honours_the_limit(): void
    {
        $root = $this->tree([
            'src/Calc.php' => "<?php\nfunction calc(\$a, \$b, \$c) { return \$a + \$b - \$c * 2; }\n",
            'tests/Unit/CalcTest.php' => "<?php\nclass CalcTest {}\n",
        ]);

        $result = $this->mutation($root)->run(['path' => 'src/Calc.php', 'limit' => 2]);

        $this->assertSame('failed', $result->status, $result->toJson());
        $this->assertSame(['killed' => 0, 'survived' => 2], $result->value);
        $this->assertCount(2, $result->failures);
    }

    public function test_property_check_validates_input_and_reproduces_a_seed(): void
    {
        $check = new PropertyCheck(new SnippetRunner(new DatabaseGuard), 8, 1);

        $this->assertSame('code', $check->run([])->failures[0]->id);
        $this->assertSame('property', $check->run(['property' => 'nope'])->failures[0]->id);

        foreach (['strlen_reverse', 'sort_idempotent'] as $preset) {
            $result = $check->run(['property' => $preset, 'cases' => 6, 'seed' => 2]);
            $this->assertSame('passed', $result->status, $result->toJson());
            $this->assertSame(6, $result->value['cases']);
        }

        $code = 'return is_array($input) || strlen($input) < 3;';
        $first = $check->run(['code' => $code, 'cases' => 12, 'seed' => 5]);
        $second = $check->run(['code' => $code, 'cases' => 12, 'seed' => 5]);

        $this->assertSame('failed', $first->status, $first->toJson());
        $this->assertStringContainsString('"case":4', $first->failures[0]->message);
        $this->assertStringContainsString('"input":', $first->failures[0]->message);
        $this->assertSame($first->failures[0]->message, $second->failures[0]->message);
    }

    public function test_architecture_check_passes_clean_rules_and_runs_architecture_tests(): void
    {
        $rules = [['path' => 'app/Http/Controllers', 'forbid_use' => ['Illuminate\\Support\\Facades\\DB']]];
        $clean = $this->tree([
            'app/Http/Controllers/CleanController.php' => "<?php\nclass CleanController {}\n",
        ]);

        $passed = (new ArchitectureCheck($clean, $this->runner($clean), [], $rules))->run([]);
        $this->assertSame('passed', $passed->status, $passed->toJson());
        $this->assertSame(['rules' => 1, 'ran' => 0], $passed->value);

        $overridden = (new ArchitectureCheck($clean, $this->runner($clean), [], []))->run(['rules' => $rules]);
        $this->assertSame('passed', $overridden->status, $overridden->toJson());
        $this->assertSame(1, $overridden->value['rules']);

        $suite = $this->tree([
            'tests/Architecture/LayersTest.php' => "<?php\nclass LayersTest {}\n",
        ]);
        $ran = (new ArchitectureCheck($suite, $this->runner($suite), ['tests/Architecture'], []))->run([]);
        $this->assertSame('passed', $ran->status, $ran->toJson());
        $this->assertSame(1, $ran->value['ran']);
        $this->assertSame(0, $ran->value['rules']);

        $broken = $this->tree([
            'tests/Architecture/LayersFailTest.php' => "<?php\nclass LayersFailTest {}\n",
        ]);
        $failed = (new ArchitectureCheck($broken, $this->runner($broken), ['tests/Architecture'], []))->run([]);
        $this->assertSame('failed', $failed->status, $failed->toJson());
        $this->assertNotEmpty($failed->failures);
    }

    private function mutation(string $root): MutationCheck
    {
        return new MutationCheck($root, $this->runner($root), new Mutator, 8);
    }

    private function runner(string $root): AffectedRunner
    {
        return new AffectedRunner(
            $root,
            new DependencyGraph($root),
            new ShardPlanner,
            new DurationBook($root.'/durations.json'),
            1,
            dirname(__DIR__).'/tests/Fixtures/phpunit-stub.php',
        );
    }

    /** @param  array<string, string>  $files */
    private function tree(array $files): string
    {
        $root = sys_get_temp_dir().'/probe-phase3-'.bin2hex(random_bytes(4));
        mkdir($root, 0775, true);
        foreach ($files as $path => $contents) {
            $absolute = $root.'/'.$path;
            $directory = dirname($absolute);
            if (! is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
            file_put_contents($absolute, $contents);
        }

        return $root;
    }
}
