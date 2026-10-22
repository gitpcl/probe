<?php

declare(strict_types=1);

namespace Probe\Runner;

use Illuminate\Contracts\Foundation\Application;
use Probe\Phase\Toolbox;
use Probe\Result\JsonResultEncoder;
use Probe\Selective\RouteMap;
use Probe\Session\SessionStore;
use Probe\Worker\SourceWatch;

final class HandlerFactory
{
    public static function make(Application $app, string $home): RequestHandler
    {
        $config = $app->make('config')->get('probe', []);
        if (! is_array($config)) {
            $config = [];
        }

        $snippets = $app->make(SnippetRunner::class);
        $database = $app->make(DatabaseGuard::class);

        return new RequestHandler(
            $snippets,
            new SessionStore($home.'/session.json'),
            $app->make(JsonResultEncoder::class),
            $database,
            (int) ($config['max_code_bytes'] ?? 100000),
            (int) ($config['recycle_after'] ?? 500),
            new Toolbox($app->basePath(), $config, $snippets, $home, $database, static fn (): array => RouteMap::fromRouter($app->make('router'))),
            new SourceWatch($app->basePath()),
            (int) ($config['max_failures'] ?? 20),
        );
    }
}
