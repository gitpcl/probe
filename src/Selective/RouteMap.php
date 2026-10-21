<?php

declare(strict_types=1);

namespace Probe\Selective;

use Illuminate\Routing\Router;

/**
 * Controller class to the quoted strings a test uses to reach it: its route URIs
 * (with a leading slash) and route names. Read from the booted router inside the
 * worker, which is the one place that resolution is cheap and exact.
 */
final class RouteMap
{
    /** @return array<string, list<string>> */
    public static function fromRouter(Router $router): array
    {
        $map = [];
        foreach ($router->getRoutes()->getRoutes() as $route) {
            $action = $route->getActionName();
            if ($action === 'Closure' || $action === '') {
                continue;
            }

            $class = ($at = strpos($action, '@')) !== false ? substr($action, 0, $at) : $action;
            $map[$class]['/'.ltrim($route->uri(), '/')] = true;
            $name = $route->getName();
            if (is_string($name) && $name !== '') {
                $map[$class][$name] = true;
            }
        }

        return array_map('array_keys', $map);
    }
}
