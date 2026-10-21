<?php

declare(strict_types=1);

namespace Probe\Tests;

use PHPUnit\Framework\TestCase;
use Probe\Selective\AffectedRunner;
use Probe\Selective\DependencyGraph;
use Probe\Selective\DurationBook;
use Probe\Selective\ShardPlanner;
use Probe\Support\ChildEnvironment;

final class ChildEnvironmentTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/probe-env-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/tests/Feature', 0775, true);
    }

    protected function tearDown(): void
    {
        ChildEnvironment::reset();
        foreach (['APP_ENV', 'DB_DATABASE', 'APP_URL', 'PROBE_KEEP', 'PROBE_STUB_ENV_LOG'] as $key) {
            putenv($key);
        }

        foreach (glob($this->root.'/{,tests/Feature/,tests/}{.,}*', GLOB_BRACE) ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        @rmdir($this->root.'/tests/Feature');
        @rmdir($this->root.'/tests');
        @rmdir($this->root);
    }

    public function test_capture_drops_keys_the_env_file_set_and_keeps_the_rest(): void
    {
        file_put_contents($this->root.'/.env', "APP_ENV=local\nDB_DATABASE=\"dev\"\nAPP_URL=\"\${APP_ENV}.test\"\n");
        putenv('APP_ENV=local');
        putenv('DB_DATABASE=dev');
        putenv('APP_URL=local.test');
        putenv('PROBE_KEEP=1');

        ChildEnvironment::capture($this->root.'/.env');
        $environment = ChildEnvironment::current();

        $this->assertIsArray($environment);
        $this->assertArrayNotHasKey('APP_ENV', $environment);
        $this->assertArrayNotHasKey('DB_DATABASE', $environment);
        $this->assertArrayNotHasKey('APP_URL', $environment);
        $this->assertSame('1', $environment['PROBE_KEEP']);
        $this->assertArrayHasKey('PATH', $environment);
    }

    public function test_a_shell_value_that_differs_from_the_file_is_kept(): void
    {
        file_put_contents($this->root.'/.env', "APP_ENV=local\n");
        putenv('APP_ENV=staging');

        ChildEnvironment::capture($this->root.'/.env');

        $environment = ChildEnvironment::current();

        $this->assertIsArray($environment);
        $this->assertSame('staging', $environment['APP_ENV']);
    }

    public function test_nothing_captured_means_the_child_inherits(): void
    {
        $this->assertNull(ChildEnvironment::current());
    }

    public function test_a_missing_env_file_captures_the_whole_environment(): void
    {
        ChildEnvironment::capture($this->root.'/.env.missing');

        $environment = ChildEnvironment::current();

        $this->assertIsArray($environment);
        $this->assertArrayHasKey('PATH', $environment);
    }

    public function test_the_test_runner_child_does_not_see_the_env_file_values(): void
    {
        file_put_contents($this->root.'/.env', "APP_ENV=local\n");
        file_put_contents($this->root.'/tests/Feature/UserTest.php', "<?php\nclass UserTest {}\n");
        $log = $this->root.'/env.log';
        putenv('APP_ENV=local');
        putenv('PROBE_STUB_ENV_LOG='.$log);
        ChildEnvironment::capture($this->root.'/.env');

        $runner = new AffectedRunner(
            $this->root,
            new DependencyGraph($this->root),
            new ShardPlanner,
            new DurationBook($this->root.'/durations.json'),
            1,
            dirname(__DIR__).'/tests/Fixtures/phpunit-stub.php',
        );

        $result = $runner->run(['paths' => ['tests/Feature/UserTest.php']]);

        $this->assertSame('passed', $result->status, $result->toJson());
        $this->assertSame("unset\n", file_get_contents($log));
        $this->assertSame('local', getenv('APP_ENV'));
    }
}
