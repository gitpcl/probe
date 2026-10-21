<?php

declare(strict_types=1);

namespace Probe\Tests;

use Illuminate\Support\Facades\Route;
use Probe\Selective\RouteMap;

final class RouteMapTest extends TestCase
{
    public function test_controllers_map_to_their_uris_and_route_names(): void
    {
        Route::post('/_/voice/process', [RouteMapFixtureController::class, 'process'])->name('voice.process');
        Route::get('/voice', [RouteMapFixtureController::class, 'show']);
        Route::get('/closure', static fn (): string => 'ok');
        Route::get('/invokable', RouteMapFixtureController::class)->name('voice.invoke');

        $map = RouteMap::fromRouter($this->app['router']);

        $needles = $map[RouteMapFixtureController::class];
        sort($needles);
        $this->assertSame(['/_/voice/process', '/invokable', '/voice', 'voice.invoke', 'voice.process'], $needles);
        $this->assertArrayNotHasKey('Closure', $map);
    }
}

final class RouteMapFixtureController
{
    public function process(): string
    {
        return 'ok';
    }

    public function show(): string
    {
        return 'ok';
    }

    public function __invoke(): string
    {
        return 'ok';
    }
}
