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
        $mutants = (new Mutator())->mutants("<?php\nreturn \$a + \$b;\n", 5);

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
        $check = new PropertyCheck(new SnippetRunner(new DatabaseGuard()), 8, 1);

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

    private function mutation(string $root): MutationCheck
    {
        return new MutationCheck($root, $this->runner($root), new DependencyGraph($root), new Mutator(), 8);
    }

    private function runner(string $root): AffectedRunner
    {
        return new AffectedRunner(
            $root,
            new DependencyGraph($root),
            new ShardPlanner(),
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
