<?php

declare(strict_types=1);

namespace Probe;

use Probe\Result\ProbeResult;
use Probe\Worker\Pool;

/**
 * In-application entry point. The agent-facing entry point is vendor/bin/probe,
 * which does not boot Laravel on the client side.
 */
final class Probe
{
    public const VERSION = '0.8.2';

    public const WARM_TARGET_MS = 150;

    public static function run(string $code, ?int $timeoutMs = null): ProbeResult
    {
        return app(Pool::class)->runCode(base_path(), $code, $timeoutMs);
    }

    public static function failures(?string $id = null): ProbeResult
    {
        $request = [];
        if ($id !== null && $id !== '') {
            $request['id'] = $id;
        }

        return app(Pool::class)->runTool(base_path(), 'get_failures', $request);
    }

    public static function rerunFailed(): ProbeResult
    {
        return app(Pool::class)->runTool(base_path(), 'rerun_failed');
    }

    /**
     * @param  list<string>  $paths
     */
    public static function runAffected(array $paths = []): ProbeResult
    {
        return app(Pool::class)->runTool(base_path(), 'run_affected', [
            'paths' => $paths,
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    public static function browser(string $url, array $extra = []): ProbeResult
    {
        return app(Pool::class)->runTool(base_path(), 'probe_browser', array_merge(['url' => $url], $extra));
    }

    public static function mutate(string $path, int $limit = 8): ProbeResult
    {
        return app(Pool::class)->runTool(base_path(), 'mutate_check', [
            'path' => $path,
            'limit' => $limit,
        ]);
    }

    public static function property(string $code, int $cases = 20, ?int $seed = null): ProbeResult
    {
        $request = ['code' => $code, 'cases' => $cases];
        if ($seed !== null) {
            $request['seed'] = $seed;
        }

        return app(Pool::class)->runTool(base_path(), 'property_check', $request);
    }

    public static function architecture(): ProbeResult
    {
        return app(Pool::class)->runTool(base_path(), 'architecture_check');
    }

    public static function formal(string $file, ?string $bridge = null): ProbeResult
    {
        $request = ['file' => $file];
        if ($bridge !== null && $bridge !== '') {
            $request['bridge'] = $bridge;
        }

        return app(Pool::class)->runTool(base_path(), 'formal_check', $request);
    }

    /** @param  list<string>  $paths */
    public static function auto(array $paths = []): ProbeResult
    {
        return app(Pool::class)->runTool(base_path(), 'auto_probe', [
            'paths' => $paths,
        ]);
    }
}
