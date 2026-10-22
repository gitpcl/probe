<?php

declare(strict_types=1);

namespace Probe\Tests;

use PHPUnit\Framework\TestCase;
use Probe\Quality\TypeCheck;

final class TypeCheckTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PROBE_STUB_LOG');
        putenv('PROBE_STUB_TS');
        putenv('PROBE_STUB_TSC');
        putenv('PROBE_STUB_SLEEP_MS');
    }

    public function test_diagnostics_map_to_file_line_and_code_and_a_continuation_stays_one_failure(): void
    {
        $root = $this->project();
        $log = $root.'/args.log';
        putenv('PROBE_STUB_LOG='.$log);
        putenv("PROBE_STUB_TS=resources/js/Foo.vue(12,5): error TS2322: Type 'string' is not assignable to type 'number'.\\nresources/js/bar.ts(3,1): error TS2304: Cannot find name 'x'.\\n  Did you mean 'y'?");

        $result = $this->check($root)->run([]);

        $this->assertSame('failed', $result->status, $result->toJson());
        $this->assertSame(['errors' => 2, 'total' => 2, 'binary' => 'tsc-stub.sh', 'project' => 'tsconfig.json'], $result->value);
        $first = $result->failures[0];
        $this->assertSame("TS2322: Type 'string' is not assignable to type 'number'. at resources/js/Foo.vue:12", $first->message);
        $this->assertSame('resources/js/Foo.vue', $first->file);
        $this->assertSame(12, $first->line);
        $this->assertSame('types', $first->type);
        $this->assertSame("TS2304: Cannot find name 'x'. Did you mean 'y'? at resources/js/bar.ts:3", $result->failures[1]->message);
        $this->assertStringContainsString("--noEmit\n--pretty\nfalse\n-p\ntsconfig.json", (string) file_get_contents($log));
    }

    public function test_paths_filter_the_report_and_total_keeps_the_whole_count(): void
    {
        $root = $this->project();
        putenv('PROBE_STUB_TS=resources/js/Foo.vue(12,5): error TS2322: bad\\nresources/js/bar.ts(3,1): error TS2304: worse');

        $one = $this->check($root)->run(['paths' => 'resources/js/Foo.vue']);
        $this->assertSame('failed', $one->status);
        $this->assertSame(1, $one->value['errors']);
        $this->assertSame(2, $one->value['total']);
        $this->assertSame('resources/js/Foo.vue', $one->failures[0]->file);

        $none = $this->check($root)->run(['paths' => ['resources/js/elsewhere']]);
        $this->assertSame('passed', $none->status);
        $this->assertSame(['errors' => 0, 'total' => 2, 'binary' => 'tsc-stub.sh', 'project' => 'tsconfig.json'], $none->value);
    }

    public function test_a_clean_run_passes(): void
    {
        $result = $this->check($this->project())->run([]);

        $this->assertSame('passed', $result->status, $result->toJson());
        $this->assertSame(0, $result->value['total']);
    }

    public function test_missing_binary_and_missing_tsconfig_are_clear_errors(): void
    {
        $root = $this->project();

        $missing = (new TypeCheck($root))->run([]);
        $this->assertSame('error', $missing->status);
        $this->assertSame('missing_tool', $missing->failures[0]->id);
        $this->assertStringContainsString('npm install --save-dev typescript@5 vue-tsc', $missing->failures[0]->message);

        unlink($root.'/tsconfig.json');
        $noProject = $this->check($root)->run([]);
        $this->assertSame('error', $noProject->status);
        $this->assertSame('tsconfig', $noProject->failures[0]->id);
    }

    public function test_vue_tsc_is_preferred_over_tsc_under_node_modules(): void
    {
        $root = $this->project();
        mkdir($root.'/node_modules/.bin', 0775, true);
        $stub = dirname(__DIR__).'/tests/Fixtures/tsc-stub.sh';
        symlink($stub, $root.'/node_modules/.bin/vue-tsc');
        symlink($stub, $root.'/node_modules/.bin/tsc');

        $this->assertSame('vue-tsc', (new TypeCheck($root))->run([])->value['binary']);

        unlink($root.'/node_modules/.bin/vue-tsc');
        $this->assertSame('tsc', (new TypeCheck($root))->run([])->value['binary']);
    }

    public function test_a_crash_is_an_error_carrying_stderr_and_a_hang_is_a_timeout(): void
    {
        $root = $this->project();

        putenv('PROBE_STUB_TSC=crash');
        $crash = $this->check($root)->run([]);
        $this->assertSame('error', $crash->status);
        $this->assertSame('tsc', $crash->failures[0]->id);
        $this->assertStringContainsString('exited 9', $crash->failures[0]->message);
        $this->assertStringContainsString("Package subpath './lib/tsc' is not defined", $crash->failures[0]->message, 'the cause on top of the trace survives the cut');
        $this->assertStringContainsString('vue-tsc/index.js:64:20', $crash->failures[0]->message, 'and so does the tail');
        $this->assertLessThanOrEqual(400, strlen($crash->failures[0]->message));

        putenv('PROBE_STUB_TSC');
        putenv('PROBE_STUB_SLEEP_MS=700');
        $hang = $this->check($root, ['timeout_ms' => 200])->run([]);
        $this->assertSame('error', $hang->status);
        $this->assertSame('timeout', $hang->failures[0]->id);
    }

    public function test_a_diagnostic_without_a_file_is_kept(): void
    {
        putenv("PROBE_STUB_TS=error TS5083: Cannot read file 'tsconfig.json'.");

        $result = $this->check($this->project())->run([]);

        $this->assertSame('failed', $result->status);
        $this->assertSame("TS5083: Cannot read file 'tsconfig.json'.", $result->failures[0]->message);
        $this->assertNull($result->failures[0]->file);
    }

    /** @param  array<string, mixed>  $config */
    private function check(string $root, array $config = []): TypeCheck
    {
        return new TypeCheck($root, $config + [
            'binary' => dirname(__DIR__).'/tests/Fixtures/tsc-stub.sh',
            'timeout_ms' => 5000,
        ]);
    }

    private function project(): string
    {
        $root = sys_get_temp_dir().'/probe-types-'.bin2hex(random_bytes(4));
        mkdir($root, 0775, true);
        file_put_contents($root.'/tsconfig.json', '{"compilerOptions":{"strict":true},"include":["resources/js"]}');

        return $root;
    }
}
