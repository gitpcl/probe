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
    | Wrap each probe in a transaction on the default connection and roll it back.
    */
    'isolate' => filter_var(env('PROBE_ISOLATE', true), FILTER_VALIDATE_BOOL),

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
];
