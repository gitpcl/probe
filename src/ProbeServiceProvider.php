<?php

declare(strict_types=1);

namespace Probe;

use Illuminate\Support\ServiceProvider;
use Probe\Console\Dispatcher;
use Probe\Console\InstallCommand;
use Probe\Console\ProbeCommand;
use Probe\Console\WorkCommand;
use Probe\Result\JsonResultEncoder;
use Probe\Runner\DatabaseGuard;
use Probe\Runner\ForkedRun;
use Probe\Runner\HandlerFactory;
use Probe\Runner\RequestHandler;
use Probe\Runner\SnippetRunner;
use Probe\Session\SessionStore;
use Probe\Support\Preloader;
use Probe\Worker\Client;
use Probe\Worker\Pool;
use Probe\Worker\Registry;
use Probe\Worker\Server;

final class ProbeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/probe.php', 'probe');

        $this->app->singleton(Client::class);
        $this->app->singleton(Registry::class);
        $this->app->singleton(Pool::class);
        $this->app->singleton(JsonResultEncoder::class);
        $this->app->singleton(DatabaseGuard::class);
        $this->app->singleton(Preloader::class);
        $this->app->singleton(Server::class);
        $this->app->singleton(Dispatcher::class);

        $this->app->singleton(SnippetRunner::class, function ($app): SnippetRunner {
            return new SnippetRunner(
                $app->make(DatabaseGuard::class),
                (int) $app->make('config')->get('probe.max_value_bytes', 1024),
                (int) $app->make('config')->get('probe.timeout_ms', 5000),
                new ForkedRun((bool) $app->make('config')->get('probe.fork', true)),
            );
        });

        $this->app->singleton(SessionStore::class, function ($app): SessionStore {
            $home = $app->make('config')->get('probe.home');
            if (! is_string($home) || $home === '') {
                $home = Project::home(base_path());
            }

            return new SessionStore($home.'/session.json');
        });

        $this->app->singleton(RequestHandler::class, function ($app): RequestHandler {
            $home = $app->make('config')->get('probe.home');
            if (! is_string($home) || $home === '') {
                $home = Project::home(base_path());
            }

            return HandlerFactory::make($app, $home);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                ProbeCommand::class,
                WorkCommand::class,
            ]);
        }

        $this->publishes([
            dirname(__DIR__).'/config/probe.php' => config_path('probe.php'),
        ], 'probe-config');
    }
}
