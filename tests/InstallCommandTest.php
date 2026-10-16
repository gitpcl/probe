<?php

declare(strict_types=1);

namespace Probe\Tests;

use Illuminate\Contracts\Console\Kernel;

final class InstallCommandTest extends TestCase
{
    public function test_commands_are_registered(): void
    {
        $commands = $this->app->make(Kernel::class)->all();

        $this->assertArrayHasKey('probe:install', $commands);
        $this->assertArrayHasKey('probe', $commands);
        $this->assertArrayHasKey('probe:work', $commands);
    }

    public function test_install_publishes_config_and_creates_the_worker_directory(): void
    {
        $config = config_path('probe.php');
        if (is_file($config)) {
            unlink($config);
        }

        $this->artisan('probe:install')
            ->expectsOutputToContain('Probe installed.')
            ->assertSuccessful();

        $this->assertFileExists($config);
        $this->assertDirectoryExists((string) config('probe.home'));

        if (is_file($config)) {
            unlink($config);
        }
    }

    public function test_config_defaults_to_sqlite_memory(): void
    {
        $this->assertSame('sqlite_memory', config('probe.database'));
        $this->assertTrue(config('probe.isolate'));
        $this->assertSame(1, config('probe.workers'));
    }
}
