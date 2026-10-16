<?php

declare(strict_types=1);

namespace Probe\Console;

use Illuminate\Console\Command;
use Probe\ProbeServiceProvider;
use Probe\Project;

final class InstallCommand extends Command
{
    protected $signature = 'probe:install';

    protected $description = 'Publish the Probe config and prepare the worker directory';

    public function handle(): int
    {
        $this->callSilent('vendor:publish', [
            '--provider' => ProbeServiceProvider::class,
            '--tag' => 'probe-config',
        ]);

        $home = config('probe.home');
        if (! is_string($home) || $home === '') {
            $home = Project::home(base_path());
        }

        if (! is_dir($home) && ! mkdir($home, 0775, true) && ! is_dir($home)) {
            $this->error('Could not create '.$home);

            return self::FAILURE;
        }

        $this->info('Probe installed.');
        $this->line('Config: config/probe.php');
        $this->line('Worker state: '.$home);
        $this->line('Agent entrypoint: vendor/bin/probe \'return 1;\'');
        $this->line('OPcache: the supervisor enables opcache.enable_cli and a file cache. See preload.php for opcache.preload.');

        return self::SUCCESS;
    }
}
