<?php

declare(strict_types=1);

namespace Probe\Tests;

use PHPUnit\Framework\TestCase;
use Probe\Quality\StaticCheck;
use Probe\Selective\DependencyGraph;

final class StaticCheckTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PROBE_STUB_LOG');
        putenv('PROBE_STUB_ERRORS');
        putenv('PROBE_STUB_PHPSTAN');
        putenv('PROBE_STUB_SLEEP_MS');
    }

    public function test_affected_scope_analyses_the_changed_file_and_its_referrers(): void
    {
        $root = $this->project();
        $log = $root.'/args.log';
        putenv('PROBE_STUB_LOG='.$log);

        $result = $this->check($root)->run(['paths' => 'app/Services/Pricing.php']);

        $this->assertSame('passed', $result->status, $result->toJson());
        $this->assertSame(['scope' => 'affected', 'errors' => 0, 'files' => 2, 'level' => 5], $result->value);
        $args = (string) file_get_contents($log);
        $this->assertStringContainsString('analyse', $args);
        $this->assertStringContainsString('--error-format=json', $args);
        $this->assertStringContainsString('--level=5', $args);
        $this->assertStringContainsString('app/Services/Pricing.php', $args);
        $this->assertStringContainsString('app/Http/Controllers/CartController.php', $args, 'the controller that news up the service is re-analysed');
        $this->assertStringNotContainsString('Unrelated.php', $args);
    }

    public function test_changed_scope_passes_only_the_given_files(): void
    {
        $root = $this->project();
        $log = $root.'/args.log';
        putenv('PROBE_STUB_LOG='.$log);

        $result = $this->check($root)->run(['paths' => ['app/Services/Pricing.php'], 'scope' => 'changed']);

        $this->assertSame('passed', $result->status, $result->toJson());
        $this->assertSame(1, $result->value['files']);
        $this->assertStringNotContainsString('CartController.php', (string) file_get_contents($log));
    }

    public function test_errors_map_to_relative_file_and_line(): void
    {
        $root = $this->project();
        $real = realpath($root) ?: $root;
        putenv('PROBE_STUB_ERRORS='.$real.'/app/Services/Pricing.php|12|Parameter #1 $cents of method total() expects int, string given.;'.$real.'/app/Http/Controllers/CartController.php||Class App\\Nope not found.');

        $result = $this->check($root)->run(['paths' => 'app/Services/Pricing.php']);

        $this->assertSame('failed', $result->status, $result->toJson());
        $this->assertSame(2, $result->value['errors']);
        $first = $result->failures[0];
        $this->assertSame('Parameter #1 $cents of method total() expects int, string given. at app/Services/Pricing.php:12', $first->message);
        $this->assertSame('app/Services/Pricing.php', $first->file);
        $this->assertSame(12, $first->line);
        $this->assertSame('static', $first->type);
        $this->assertSame('Class App\\Nope not found. at app/Http/Controllers/CartController.php', $result->failures[1]->message);
        $this->assertNull($result->failures[1]->line);
    }

    public function test_missing_binary_is_missing_tool_with_the_install_hint(): void
    {
        $root = $this->project();

        $result = $this->check($root, ['binary' => '/no/such/phpstan'])->run(['paths' => 'app/Services/Pricing.php']);

        $this->assertSame('error', $result->status);
        $this->assertSame('missing_tool', $result->failures[0]->id);
        $this->assertStringContainsString('larastan/larastan', $result->failures[0]->message);
    }

    public function test_the_projects_neon_owns_the_level_unless_the_request_overrides_it(): void
    {
        $root = $this->project();
        file_put_contents($root.'/phpstan.neon', "parameters:\n    level: 7\n    paths:\n        - app\n");
        $log = $root.'/args.log';
        putenv('PROBE_STUB_LOG='.$log);

        $own = $this->check($root)->run(['paths' => 'app/Services/Pricing.php']);
        $this->assertSame('passed', $own->status, $own->toJson());
        $this->assertArrayNotHasKey('level', $own->value);
        $this->assertStringNotContainsString('--level', (string) file_get_contents($log));

        file_put_contents($log, '');
        $forced = $this->check($root)->run(['paths' => 'app/Services/Pricing.php', 'level' => 8]);
        $this->assertSame(8, $forced->value['level']);
        $this->assertStringContainsString('--level=8', (string) file_get_contents($log));
    }

    public function test_with_a_neon_the_selected_scopes_run_the_configured_paths_and_filter_the_report(): void
    {
        $root = $this->project();
        $real = realpath($root) ?: $root;
        file_put_contents($root.'/phpstan.neon', "parameters:\n    level: 7\n    paths:\n        - app\n");
        $log = $root.'/args.log';
        putenv('PROBE_STUB_LOG='.$log);
        putenv('PROBE_STUB_ERRORS='.$real.'/app/Models/Unrelated.php|3|Unrelated is wrong.;'.$real.'/app/Services/Pricing.php|12|Pricing is wrong.;'.$real.'/app/Http/Controllers/CartController.php|4|Cart is wrong.');

        $affected = $this->check($root)->run(['paths' => 'app/Services/Pricing.php']);

        $this->assertSame('failed', $affected->status, $affected->toJson());
        $this->assertSame(['scope' => 'affected', 'errors' => 2, 'total' => 3, 'files' => 2], $affected->value);
        $this->assertSame(['1', '2'], array_map(static fn ($failure) => $failure->id, $affected->failures), 'ids follow the filtered list');
        $this->assertSame(['app/Services/Pricing.php', 'app/Http/Controllers/CartController.php'], array_map(static fn ($failure) => $failure->file, $affected->failures));
        $this->assertStringNotContainsString('.php', (string) file_get_contents($log), 'the configured paths run so the result cache applies');

        $changed = $this->check($root)->run(['paths' => 'app/Services/Pricing.php', 'scope' => 'changed']);

        $this->assertSame(['scope' => 'changed', 'errors' => 1, 'total' => 3, 'files' => 1], $changed->value);
        $this->assertSame('Pricing is wrong. at app/Services/Pricing.php:12', $changed->failures[0]->message);
    }

    public function test_scope_all_runs_the_projects_own_paths_and_needs_a_neon(): void
    {
        $root = $this->project();
        $log = $root.'/args.log';
        putenv('PROBE_STUB_LOG='.$log);

        $without = $this->check($root)->run(['scope' => 'all']);
        $this->assertSame('error', $without->status);
        $this->assertSame('config', $without->failures[0]->id);

        file_put_contents($root.'/phpstan.neon', "parameters:\n    level: 7\n    paths:\n        - app\n");
        $with = $this->check($root)->run(['scope' => 'all']);
        $this->assertSame('passed', $with->status, $with->toJson());
        $this->assertSame(['scope' => 'all', 'errors' => 0], $with->value);
        $this->assertStringNotContainsString('.php', (string) file_get_contents($log), 'scope all leaves the paths to phpstan.neon');
    }

    public function test_a_crash_is_an_error_carrying_stderr_and_a_hang_is_a_timeout(): void
    {
        $root = $this->project();

        putenv('PROBE_STUB_PHPSTAN=crash');
        $crash = $this->check($root)->run(['paths' => 'app/Services/Pricing.php']);
        $this->assertSame('error', $crash->status);
        $this->assertSame('phpstan', $crash->failures[0]->id);
        $this->assertStringContainsString('exited 255', $crash->failures[0]->message);
        $this->assertStringContainsString('memory size', $crash->failures[0]->message);

        putenv('PROBE_STUB_PHPSTAN');
        putenv('PROBE_STUB_SLEEP_MS=700');
        $hang = $this->check($root, ['timeout_ms' => 200])->run(['paths' => 'app/Services/Pricing.php']);
        $this->assertSame('error', $hang->status);
        $this->assertSame('timeout', $hang->failures[0]->id);
    }

    public function test_bad_scope_and_missing_paths_are_clear_errors(): void
    {
        $root = $this->project();

        $this->assertSame('scope', $this->check($root)->run(['scope' => 'everything'])->failures[0]->id);
        $this->assertSame('paths', $this->check($root)->run(['paths' => []])->failures[0]->id);
    }

    /** @param  array<string, mixed>  $config */
    private function check(string $root, array $config = []): StaticCheck
    {
        return new StaticCheck($root, new DependencyGraph($root), $config + [
            'binary' => dirname(__DIR__).'/tests/Fixtures/phpstan-stub.php',
            'timeout_ms' => 5000,
        ]);
    }

    private function project(): string
    {
        $root = sys_get_temp_dir().'/probe-static-'.bin2hex(random_bytes(4));
        $files = [
            'app/Services/Pricing.php' => "<?php\nnamespace App\\Services;\nfinal class Pricing { public function total(int \$cents): int { return \$cents; } }\n",
            'app/Http/Controllers/CartController.php' => "<?php\nnamespace App\\Http\\Controllers;\nuse App\\Services\\Pricing;\nfinal class CartController { public function show(Pricing \$pricing): int { return \$pricing->total(1); } }\n",
            'app/Models/Unrelated.php' => "<?php\nnamespace App\\Models;\nfinal class Unrelated {}\n",
        ];
        foreach ($files as $path => $contents) {
            $absolute = $root.'/'.$path;
            if (! is_dir(dirname($absolute))) {
                mkdir(dirname($absolute), 0775, true);
            }
            file_put_contents($absolute, $contents);
        }

        return $root;
    }
}
