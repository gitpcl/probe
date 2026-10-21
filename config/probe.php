<?php

declare(strict_types=1);

/*
| Probe configuration.
|
| Agents should call `vendor/bin/probe`. That process does not boot Laravel.
| Socket, home, and worker count therefore follow PROBE_SOCKET, PROBE_HOME,
| and PROBE_WORKERS from the environment or the project .env file.
*/

$home = env('PROBE_HOME');

return [
    'home' => is_string($home) && $home !== '' ? $home : storage_path('framework/probe'),

    'socket' => env('PROBE_SOCKET'),

    'workers' => max(1, (int) env('PROBE_WORKERS', 1)),

    /*
    | sqlite_memory points the worker's default connection at SQLite :memory:
    | so a probe does not write the host database. host keeps the application's
    | configured default connection. Named connections stay available either way.
    */
    'database' => env('PROBE_DATABASE', 'sqlite_memory'),

    'migrate' => filter_var(env('PROBE_MIGRATE', true), FILTER_VALIDATE_BOOL),

    /*
    | Optional PHP run once after the worker boots and migrations finish.
    | It is outside the per-probe transaction, so schema created here stays
    | for the life of the worker while later probe writes still roll back.
    */
    'prepare' => env('PROBE_PREPARE'),

    /*
    | Wrap each probe in a transaction on the default connection and roll it back.
    */
    'isolate' => filter_var(env('PROBE_ISOLATE', true), FILTER_VALIDATE_BOOL),

    /*
    | Run each probe in a forked child so nothing it changes in the worker survives:
    | config, fakes, Carbon::setTestNow, auth, a fatal error or exit(). Needs pcntl
    | and posix; without them probes run inside the worker process.
    */
    'fork' => filter_var(env('PROBE_FORK', true), FILTER_VALIDATE_BOOL),

    'timeout_ms' => (int) env('PROBE_TIMEOUT_MS', 5000),

    'boot_timeout_seconds' => (int) env('PROBE_BOOT_TIMEOUT', 60),

    /*
    | Recycle the worker after this many probes. The next call boots a fresh
    | process. 0 leaves the worker up until it is stopped.
    */
    'recycle_after' => (int) env('PROBE_RECYCLE_AFTER', 500),

    'max_code_bytes' => (int) env('PROBE_MAX_CODE_BYTES', 100000),

    'max_value_bytes' => (int) env('PROBE_MAX_VALUE_BYTES', 1024),

    /*
    | Extra absolute paths compiled into OPcache when a worker boots.
    | The package also ships preload.php for opcache.preload.
    */
    'preload' => [],

    /*
    | The supervisor starts workers with opcache.enable_cli and a file cache.
    | Set PROBE_OPCACHE=0 to pass the plain PHP binary instead.
    */
    'opcache' => filter_var(env('PROBE_OPCACHE', true), FILTER_VALIDATE_BOOL),

    /*
    | Browser probes. auto prefers Lightpanda, then Playwright.
    | Interaction steps always use Playwright. Point lightpanda or playwright
    | at an absolute path to override the PATH lookup.
    */
    'browser' => [
        'engine' => env('PROBE_BROWSER', 'auto'),
        'persistent' => filter_var(env('PROBE_BROWSER_PERSISTENT', true), FILTER_VALIDATE_BOOL),
        'server' => env('PROBE_BROWSER_SERVER'),
        'lightpanda' => env('PROBE_LIGHTPANDA', 'lightpanda'),
        'playwright' => env('PROBE_PLAYWRIGHT', 'playwright'),
        'node' => env('PROBE_NODE', 'node'),
        'base_url' => env('PROBE_BASE_URL', env('APP_URL', 'http://127.0.0.1:8000')),
        'timeout_ms' => (int) env('PROBE_BROWSER_TIMEOUT_MS', 8000),
        'lightpanda_args' => ['fetch', '--dump', 'html'],
    ],

    /*
    | run_affected. runner is an optional Pest or PHPUnit binary.
    | workers null follows the warm pool size and is the shard count.
    | full_suite_under_ms runs every test when the whole suite is estimated under it; 0 keeps graph selection only.
    | mode fork runs shards as forked children of the warm worker (no PHP start, no Composer, no boot per shard);
    | process starts a runner process per shard. workers null is one shard per CPU, capped by the file count.
    | depth is how many hops of static references (use, new, ::, type hints) a change follows through app/ and database/.
    */
    'selective' => [
        'runner' => env('PROBE_TEST_RUNNER'),
        'workers' => env('PROBE_SELECTIVE_WORKERS'),
        'timeout_ms' => (int) env('PROBE_SELECTIVE_TIMEOUT_MS', 120000),
        'full_suite_under_ms' => (int) env('PROBE_FULL_SUITE_UNDER_MS', 15000),
        'mode' => env('PROBE_SELECTIVE_MODE', 'fork'),
        'depth' => (int) env('PROBE_SELECTIVE_DEPTH', 4),
    ],

    'mutate' => [
        'limit' => (int) env('PROBE_MUTATE_LIMIT', 8),
    ],

    'property' => [
        'cases' => (int) env('PROBE_PROPERTY_CASES', 20),
        'seed' => (int) env('PROBE_PROPERTY_SEED', 1),
    ],

    /*
    | forbid_use rules are static. tests/Architecture is executed when present,
    | which is the Pest arch() integration path.
    */
    'architecture' => [
        'paths' => ['tests/Architecture', 'tests/Arch'],
        'rules' => [],
    ],

    /*
    | Optional prover binaries. A missing binary returns missing_tool.
    */
    'formal' => [
        'alloy' => env('PROBE_ALLOY', 'alloy'),
        'tla' => env('PROBE_TLA', 'tlc'),
        'dafny' => env('PROBE_DAFNY', 'dafny'),
        'lean' => env('PROBE_LEAN', 'lean'),
        'timeout_ms' => (int) env('PROBE_FORMAL_TIMEOUT_MS', 20000),
        'args' => [],
    ],

    /*
    | auto_probe uses the heuristic selector unless llm is true and an endpoint is set.
    | CI should leave llm false so no network key is required.
    */
    'intelligence' => [
        'limit' => (int) env('PROBE_AUTO_LIMIT', 8),
        'llm' => filter_var(env('PROBE_LLM', false), FILTER_VALIDATE_BOOL),
        'endpoint' => env('PROBE_LLM_ENDPOINT'),
        'model' => env('PROBE_LLM_MODEL', ''),
        'api_key' => env('PROBE_LLM_API_KEY'),
        'timeout_ms' => (int) env('PROBE_LLM_TIMEOUT_MS', 2000),
    ],
];
