<?php

declare(strict_types=1);

namespace Probe\Runner;

use Illuminate\Contracts\Foundation\Application;
use Probe\Phase\Toolbox;
use Probe\Result\JsonResultEncoder;
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

        return new RequestHandler(
            $snippets,
            new SessionStore($home.'/session.json'),
            $app->make(JsonResultEncoder::class),
            $app->make(DatabaseGuard::class),
            (int) ($config['max_code_bytes'] ?? 100000),
            (int) ($config['recycle_after'] ?? 500),
            new Toolbox($app->basePath(), $config, $snippets, $home),
            new SourceWatch($app->basePath()),
        );
    }
}
