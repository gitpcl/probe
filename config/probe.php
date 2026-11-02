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
    | Failures listed in a run result. The rest stay in the session, so
    | get_failures --id=N opens any of them; failures_total says how many there were.
    */
    'max_failures' => (int) env('PROBE_MAX_FAILURES', 20),

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
    | Browser probes. auto uses the Playwright npm package installed in the app
    | (npm install -D playwright, resolved from the project root) when node is
    | available, else Lightpanda on PATH, else the one-shot Playwright script.
    | Interaction steps always use Playwright. A global playwright CLI does not
    | stand in for the package. lightpanda, playwright and node are looked up
    | on PATH unless given as absolute paths.
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
    | workers is the shard count; null is one shard per CPU, capped by the file count.
    | full_suite_under_ms runs every test when the whole suite is estimated under it; 0 keeps graph selection only.
    | mode fork runs shards as forked children of the warm worker (no PHP start, no Composer, no boot per shard);
    | process starts a runner process per shard. More than one shard sets Laravel's parallel-testing
    | variables, so each shard gets its own <database>_test_probe_<n>.
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
    | static_check runs PHPStan (Larastan is a PHPStan extension) over the files a
    | change touches and the files that reference them. level applies only when the
    | project has no phpstan.neon to say so itself. PHPStan's own result cache does
    | the caching; a missing binary returns missing_tool.
    */
    'static' => [
        'binary' => env('PROBE_PHPSTAN', 'vendor/bin/phpstan'),
        'level' => (int) env('PROBE_PHPSTAN_LEVEL', 5),
        'memory_limit' => env('PROBE_PHPSTAN_MEMORY', '2G'),
        'timeout_ms' => (int) env('PROBE_STATIC_TIMEOUT_MS', 120000),
    ],

    /*
    | type_check runs vue-tsc, else tsc, with --noEmit over the project's tsconfig.
    | The compiler's incremental mode does the caching; a missing binary returns missing_tool.
    */
    'types' => [
        'binary' => env('PROBE_TSC'),
        'project' => env('PROBE_TSCONFIG', 'tsconfig.json'),
        'timeout_ms' => (int) env('PROBE_TYPES_TIMEOUT_MS', 120000),
    ],

    /*
    | contract_check sends each documented OpenAPI operation through the booted app
    | (Accept application/json, inside the probe's transaction) and validates the
    | JSON response against the spec's schema. spec null searches openapi.{yaml,json}
    | in the root, docs/, public/ and storage/api-docs; auth is a user id or email
    | to log in first; headers go on every request; limit caps messages per operation.
    */
    'contract' => [
        'spec' => env('PROBE_OPENAPI'),
        'auth' => env('PROBE_CONTRACT_AUTH'),
        'headers' => [],
        'limit' => (int) env('PROBE_CONTRACT_LIMIT', 10),
        'timeout_ms' => (int) env('PROBE_CONTRACT_TIMEOUT_MS', 60000),
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
