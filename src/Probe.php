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
    public const VERSION = '0.1.0';

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
     * Phase 2 stub. The worker returns status "unsupported".
     *
     * @param  list<string>  $paths
     */
    public static function runAffected(array $paths = []): ProbeResult
    {
        return app(Pool::class)->runTool(base_path(), 'run_affected', [
            'paths' => array_values($paths),
        ]);
    }
}
