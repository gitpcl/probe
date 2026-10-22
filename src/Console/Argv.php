<?php

declare(strict_types=1);

namespace Probe\Console;

final class Argv
{
    /**
     * @param  list<string>  $argv
     */
    public static function parse(array $argv): Invocation
    {
        $tool = 'probe';
        $file = null;
        $timeout = null;
        $id = null;
        $paths = null;
        $cold = false;
        $benchmark = false;
        $stop = false;
        $status = false;
        $help = false;
        $version = false;
        $samples = 20;
        $url = null;
        $bridge = null;
        $cases = null;
        $seed = null;
        $engine = null;
        $contains = null;
        $absent = null;
        $limit = null;
        $property = null;
        $steps = null;
        $httpStatus = null;
        $scope = null;
        $level = null;
        $project = null;
        $spec = null;
        $routes = null;
        $auth = null;
        $positionals = [];

        $args = array_slice($argv, 1);
        $count = count($args);

        for ($index = 0; $index < $count; $index++) {
            $arg = $args[$index];

            if ($arg === '--') {
                $positionals = array_merge($positionals, array_slice($args, $index + 1));
                break;
            }

            if ($arg === '--help' || $arg === '-h') {
                $help = true;

                continue;
            }

            if ($arg === '--version' || $arg === '-V') {
                $version = true;

                continue;
            }

            if ($arg === '--cold') {
                $cold = true;

                continue;
            }

            if ($arg === '--benchmark') {
                $benchmark = true;

                continue;
            }

            if ($arg === '--stop') {
                $stop = true;

                continue;
            }

            if ($arg === '--status') {
                $status = true;

                continue;
            }

            if (! str_starts_with($arg, '--')) {
                $positionals[] = $arg;

                continue;
            }

            $name = $arg;
            $value = null;
            if (str_contains($arg, '=')) {
                [$name, $value] = explode('=', $arg, 2);
            } else {
                $next = $args[$index + 1] ?? null;
                if ($next !== null && ! str_starts_with($next, '-')) {
                    $value = $next;
                    $index++;
                }
            }

            switch ($name) {
                case '--tool':
                    if ($value !== null && $value !== '') {
                        $tool = $value;
                    }
                    break;
                case '--file':
                    $file = $value;
                    break;
                case '--timeout':
                    $timeout = $value !== null ? (int) $value : null;
                    break;
                case '--id':
                    $id = $value;
                    break;
                case '--paths':
                    $paths = $value;
                    break;
                case '--samples':
                    $samples = $value !== null ? (int) $value : 20;
                    break;
                case '--url':
                    $url = $value;
                    break;
                case '--bridge':
                    $bridge = $value;
                    break;
                case '--cases':
                    $cases = $value !== null ? (int) $value : null;
                    break;
                case '--seed':
                    $seed = $value !== null ? (int) $value : null;
                    break;
                case '--engine':
                    $engine = $value;
                    break;
                case '--contains':
                    $contains = $value;
                    break;
                case '--absent':
                    $absent = $value;
                    break;
                case '--limit':
                    $limit = $value !== null ? (int) $value : null;
                    break;
                case '--property':
                    $property = $value;
                    break;
                case '--steps':
                    $steps = $value;
                    break;
                case '--http-status':
                    $httpStatus = $value !== null ? (int) $value : null;
                    break;
                case '--scope':
                    $scope = $value;
                    break;
                case '--level':
                    $level = $value !== null ? (int) $value : null;
                    break;
                case '--project':
                    $project = $value;
                    break;
                case '--spec':
                    $spec = $value;
                    break;
                case '--routes':
                    $routes = $value;
                    break;
                case '--auth':
                    $auth = $value;
                    break;
                default:
                    $positionals[] = $arg;
            }
        }

        return new Invocation(
            tool: $tool,
            code: $positionals === [] ? null : implode(' ', $positionals),
            file: $file,
            timeoutMs: $timeout,
            id: $id,
            paths: $paths,
            cold: $cold,
            benchmark: $benchmark,
            stop: $stop,
            status: $status,
            help: $help,
            version: $version,
            samples: max(1, $samples),
            url: $url,
            bridge: $bridge,
            cases: $cases,
            seed: $seed,
            engine: $engine,
            contains: $contains,
            absent: $absent,
            limit: $limit,
            property: $property,
            steps: $steps,
            httpStatus: $httpStatus,
            scope: $scope,
            level: $level,
            project: $project,
            spec: $spec,
            routes: $routes,
            auth: $auth,
        );
    }
}
