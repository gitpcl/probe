# Probe

Agent-first testing runtime for Laravel. Phase 1 keeps a warm PHP worker booted and answers a throwaway probe with one compact JSON object.

Pest and PHPUnit stay the suite runners. Probe sits beside them for the agent loop: run a snippet, read a tiny result, repeat.

The design proposal is in [DESIGN.md](DESIGN.md). This package is Phase 1 only.

## Install

```bash
composer require gitpcl/probe --dev
php artisan probe:install
```

PHP 8.2 or newer. Laravel 11 or 12. `probe:install` publishes `config/probe.php` and creates the worker directory.

The agent entrypoint is `vendor/bin/probe`. It does not boot Laravel in the client. `php artisan probe` runs the same tools after a normal Artisan boot, so it is the slower path.

## Quick start

```bash
vendor/bin/probe 'return 1 + 1;'
```

```json
{"status":"passed","duration_ms":4,"failures":[],"value":2}
```

`duration_ms` varies. A green probe with no return value is only status, duration, and an empty `failures` array.

```bash
vendor/bin/probe 'use App\Models\User; return User::query()->count();'
vendor/bin/probe --tool=get_failures
vendor/bin/probe --tool=rerun_failed
vendor/bin/probe --tool=run_affected --paths=app/Models/User.php
vendor/bin/probe --status
vendor/bin/probe --stop
```

From PHP inside a booted app:

```php
use Probe\Probe;

$result = Probe::run('return 1 + 1;');
$result->status; // passed
```

Exit codes: `0` passed, `1` failed, `2` error or unsupported.

## Snippets

A snippet is statements inside the application, wrapped in a closure. Leading `use` lines are kept as real imports. A `namespace` declaration is rejected. Facade aliases (`DB`, `Cache`, `Schema`) and helpers (`app()`, `config()`, `now()`) work. Fully qualified class names work.

Return a value to include it. `null` is omitted so a green result stays small. `0` and `false` are included. Values larger than `max_value_bytes` (default 1024) become a short truncation marker.

`echo` output is discarded. `exit` and `die` stop the worker; the next probe boots another one.

## Result shape

Passing output is a single JSON object. The green form is about a dozen tokens, under the design budget of ~40.

| Field | When |
| --- | --- |
| `status` | `passed`, `failed`, `error`, or `unsupported` |
| `duration_ms` | Time inside the worker, rounded to milliseconds |
| `failures` | Empty when green. Compact failures are `id` and `message` |
| `value` | Present when the snippet returned a non-null value |
| `tool`, `phase` | Present when a later-phase tool is called |
| `rerun` | `false` when `rerun_failed` had nothing to run |

`get_failures` returns the same status plus `type`, `file`, `line`, and `trace` for the last probe. That is the progressive-detail hook. The compact probe path does not include the trace.

## Tools

| Tool | Phase | Behavior |
| --- | --- | --- |
| `probe` | 1 | Run a snippet in the warm app |
| `get_failures` | 1 | Detail for the last probe |
| `rerun_failed` | 1 | Run the last failed snippet again |
| `run_affected` | 2 | Returns `unsupported` |
| `probe_browser` | 2 | Returns `unsupported` |
| `mutate_check`, `property_check` | 3 | Returns `unsupported` |
| `formal_check` | 4 | Returns `unsupported` |

An MCP server can be a thin wrapper over the same messages later. Phase 1 speaks JSON over a Unix socket and over the CLI.

Request:

```json
{"tool":"probe","code":"return 1;","timeout_ms":5000}
```

Response:

```json
{"status":"passed","duration_ms":3,"failures":[],"value":1}
```

## Warm workers

`vendor/bin/probe` starts `php artisan probe:work` on the first call and reuses it after that. The worker is a normal Artisan process that boots Laravel once, then reads one JSON object per Unix-socket connection.

State lives in `storage/framework/probe/` (`active.json`, `session.json`, the socket). Override the location with `PROBE_HOME` and `PROBE_SOCKET` in `.env` so the thin client and Artisan agree. The supervisor lock stops two clients from booting two workers.

The pool size is `PROBE_WORKERS` (default 1). Phase 1 tools always talk to worker 0 because the last-failure session is per worker directory, shared through `session.json`. Extra workers can be started for later parallel runs. The supervisor restarts a worker that dies, and recycles it after `recycle_after` probes (default 500).

`--cold` stops the current worker first. `--stop` shuts it down.

### OPcache and preload

The hot path is the already-booted process: files stay loaded, so the next probe does not compile the framework again.

On top of that, the supervisor starts workers with `opcache.enable_cli=1` and `opcache.file_cache` under the worker directory, unless `PROBE_OPCACHE=0`. The worker also calls `Probe\Support\Preloader` at boot, which `opcache_compile_file`s the package classes plus any paths in `config('probe.preload')`.

For PHP-FPM style preloading, point `opcache.preload` at `vendor/gitpcl/probe/preload.php`. CLI workers do not share an FPM preload parent; the file cache is what helps the next cold start.

### SQLite memory

`probe.database` defaults to `sqlite_memory`. When `pdo_sqlite` is loaded, the worker's **default** connection becomes SQLite `:memory:`. `probe.migrate` runs migrations once on that connection at boot. `probe.isolate` wraps each probe in a transaction and rolls it back.

Named connections configured by the host app stay available. A snippet that calls `DB::connection('mysql')` talks to that connection, and those writes are not rolled back.

Set `PROBE_DATABASE=host` to keep the application's default connection.

SQLite commits some DDL immediately. Create tables in their own probe, then insert data in the next one if you need the rollback to cover the insert.

## Pest and PHPUnit

Probe does not replace the test runner and does not register a PHPUnit extension. `vendor/bin/pest` and `vendor/bin/phpunit` behave as they did before this package was installed. Use them for the suite. Use Probe for throwaway snippets.

## Benchmark

From the Laravel app:

```bash
vendor/bin/probe --benchmark
```

The command prints one JSON object:

| Field | Meaning |
| --- | --- |
| `cold_ms` | Fresh PHP process, worker not running: process start, Laravel boot, trivial `return 1;` |
| `process_median_ms` | Fresh PHP process against an already warm worker. This is what an agent pays per call |
| `roundtrip_median_ms` | Socket round trip from an already running client |
| `met_target` | `process_median_ms` is under 150 |

Numbers from this package's Testbench fixture on PHP 8.3.6 (a small Laravel boot, not a full application skeleton) are filled in after measurement. Run the command in the target app for numbers that match that app. Cold boot grows with the app. The warm process time is dominated by PHP startup plus the socket round trip.

## Configuration

Published to `config/probe.php`.

| Key | Default | Role |
| --- | --- | --- |
| `database` | `sqlite_memory` | `sqlite_memory` or `host` |
| `migrate` | `true` | Migrate the probe connection at worker boot |
| `isolate` | `true` | Roll back the default connection after each probe |
| `workers` | `1` | Pool size |
| `timeout_ms` | `5000` | Per-probe wall clock. `pcntl` alarms at one-second resolution |
| `recycle_after` | `500` | Probes before the worker exits |
| `max_value_bytes` | `1024` | Largest returned value kept inline |
| `preload` | `[]` | Extra files to compile at boot |
| `opcache` | `true` | Pass OPcache file-cache flags to the worker |

Timeouts need `ext-pcntl`. A tight CPU loop may run until the client gives up and the supervisor kills the worker. The next call boots a fresh one.

## Security

Probe executes arbitrary PHP inside the application. It is a local dev dependency. Do not expose the socket, do not install it with Composer in production (`--dev` keeps it out of `--no-dev` installs), and do not point it at untrusted snippets.

## Phase 1 scope

Included: agent tools above, warm worker pool, compact JSON, SQLite memory, config publishing, package tests.

Not included: Lightpanda or Playwright, selective test impact, mutation testing, property checks, and formal bridges. Those tools return `unsupported` with a phase number. Empty interfaces live in `Probe\Contract` for the later work.

## License

MIT.
