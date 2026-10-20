<?php

declare(strict_types=1);

namespace Probe\Console;

use Illuminate\Console\Command;
use Probe\Probe;
use Probe\Project;
use Probe\Runner\DatabaseGuard;
use Probe\Runner\HandlerFactory;
use Probe\Support\Preloader;
use Probe\Worker\Registry;
use Probe\Worker\Server;

final class WorkCommand extends Command
{
    protected $signature = 'probe:work
        {--socket= : Unix socket to listen on}
        {--index=0 : Worker index in the pool}
        {--home= : State directory for the registry and session}';

    protected $description = 'Run a warm Probe worker';

    public function handle(DatabaseGuard $database, Preloader $preloader, Server $server): int
    {
        ignore_user_abort(true);
        set_time_limit(0);

        $socketOption = $this->option('socket');
        $homeOption = $this->option('home');
        $index = (int) $this->option('index');
        $root = base_path();
        $home = is_string($homeOption) && $homeOption !== ''
            ? $homeOption
            : (is_string(config('probe.home')) && config('probe.home') !== '' ? (string) config('probe.home') : Project::home($root));
        $socket = is_string($socketOption) && $socketOption !== ''
            ? $socketOption
            : Project::socket($root, $index);

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGHUP, SIG_IGN);
            pcntl_signal(SIGTERM, static function () use ($socket): void {
                if (file_exists($socket)) {
                    @unlink($socket);
                }
                exit(0);
            });
            pcntl_signal(SIGINT, static function () use ($socket): void {
                if (file_exists($socket)) {
                    @unlink($socket);
                }
                exit(0);
            });
        }

        $extra = config('probe.preload', []);
        $preloader->warm(is_array($extra) ? array_values(array_filter($extra, 'is_string')) : []);
        $mode = $database->boot($this->laravel);

        $prepare = config('probe.prepare');
        if (is_string($prepare) && trim($prepare) !== '') {
            probe_execute_snippet($prepare);
        }

        $handler = HandlerFactory::make($this->laravel, $home);

        $registry = new Registry;
        $meta = array_filter([
            'index' => $index,
            'pid' => getmypid(),
            'socket' => $socket,
            'database' => $mode,
            'booted_at' => time(),
            'version' => Probe::VERSION,
            'migrate_error' => $database->migrateError(),
            'notice' => $database->notice(),
        ], static fn (mixed $value): bool => $value !== null);

        $activeFile = rtrim($home, '/').'/active.json';
        $registry->publish($activeFile, $meta);

        try {
            $server->listen($socket, function (array $request) use ($handler, $server): array {
                $response = $handler->handle($request);
                if ($handler->shouldStop()) {
                    $server->stop();
                }

                return $response;
            });
        } finally {
            $registry->unpublish($activeFile, $index);
        }

        return self::SUCCESS;
    }
}
