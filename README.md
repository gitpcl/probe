# Probe

Agent-first testing runtime for Laravel. A warm PHP worker answers throwaway probes with one compact JSON object. The same result shape covers affected tests, browser checks, mutation, properties, architecture rules, and optional formal provers.

Pest and PHPUnit stay the suite runners. Probe sits beside them for the agent loop: run a check, read a tiny result, repeat.

The design proposal is in [DESIGN.md](DESIGN.md). Phases 1 to 5 are implemented here.

## Install

```bash
composer require gitpcl/probe --dev
php artisan probe:install
```

PHP 8.2 or newer. Laravel 12 or 13 (Laravel 13 needs PHP 8.3 or newer). `probe:install` publishes `config/probe.php` and creates the worker directory.

The agent entrypoint is `vendor/bin/probe`. It does not boot Laravel in the client. `php artisan probe` runs the same tools after a normal Artisan boot, so it is the slower path.

## Quick start

```bash
vendor/bin/probe 'return 1 + 1;'
```

```json
{"status":"passed","duration_ms":4,"failures":[],"value":2}
```

`duration_ms` varies. A green probe with no return value is only status, duration, and an empty `failures` array.

`vendor/bin/probe` is a shell script. A bare snippet (one argument, no flags) goes straight to the live worker over `nc` in about 10 ms, without starting PHP on the client side; Composer's generated proxy in `vendor/bin` adds about 8 ms on top, so an agent that wants the floor calls `vendor/gitpcl/probe/bin/probe`. Everything else, and any miss (no worker yet, an `nc` without Unix-socket support, a changed source file), runs the PHP client in `bin/probe.php`, which also supervises the worker. `--benchmark` reports both as `shell_median_ms` and `process_median_ms`.

```bash
vendor/bin/probe 'use App\Models\User; return User::query()->count();'
vendor/bin/probe --tool=get_failures
vendor/bin/probe --tool=rerun_failed
vendor/bin/probe --tool=run_affected --paths=app/Models/User.php
vendor/bin/probe --tool=probe_browser --url=http://127.0.0.1:8000 --contains=Laravel
vendor/bin/probe --tool=mutate_check --file=app/Support/Money.php --limit=8
vendor/bin/probe --tool=property_check --property=addition_commutative
vendor/bin/probe --tool=architecture_check
vendor/bin/probe --tool=formal_check --file=specs/auth.als
vendor/bin/probe --tool=auto_probe --paths=app/Models/User.php
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

## MCP

`vendor/bin/probe-mcp` is an MCP server over stdio: one long-lived process per agent session, so a tool call costs one socket round trip to the warm worker and no PHP startup. Register it from the application directory:

```bash
claude mcp add probe -- "$PWD/vendor/bin/probe-mcp"
```

The server keeps the project it was started in. Tools: `probe`, `get_failures`, `rerun_failed`, `run_affected`, `probe_browser`, `mutate_check`, `property_check`, `architecture_check`, `formal_check`, `auto_probe`, `static_check`, `type_check`, `contract_check`, `status`, `cold`, `stop`. Each returns one text item holding the same compact JSON the CLI prints, with `isError` set whenever the status is not `passed`.

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
| `value` | Snippet return value, or a small payload such as `ran`, `engine`, `killed`, or `selector` |
| `rerun` | `false` when `rerun_failed` had nothing to run |

`get_failures` returns the first failure of the last result in full (`type`, `file`, `line`, `trace`) plus `value.total`; `--id=N` opens another one. Traces leave out Probe's own frames. That is the progressive-detail hook. The compact probe path does not include the trace.

## Tools

| Tool | Phase | Behavior |
| --- | --- | --- |
| `probe` | 1 | Run a snippet in the warm app |
| `get_failures` | 1 | Detail for the first failure of the last result; `--id` picks another |
| `rerun_failed` | 1 | Run the last failed probe or check again |
| `probe_browser` | 2 | Fetch a page with Lightpanda, or Playwright when steps need it |
| `run_affected` | 2 | Run the Pest or PHPUnit files impacted by the given paths |
| `mutate_check` | 3 | Flip operators in one file and see which mutants survive |
| `property_check` | 3 | Run a snippet or a built-in property against generated cases |
| `architecture_check` | 3 | Enforce `forbid_use` rules and run `tests/Architecture` |
| `formal_check` | 4 | Run Alloy, TLC, Dafny, or Lean when that binary exists |
| `auto_probe` | 5 | Pick a few impacted tests, or a class smoke probe |
| `static_check` | 6 | Run PHPStan (Larastan) over the files a change touches and the files that reference them |
| `type_check` | 6 | Run `vue-tsc`, else `tsc`, with `--noEmit` over the project tsconfig |
| `contract_check` | 6 | Send the OpenAPI operations through the booted app and validate the JSON responses |

An MCP server can still be a thin wrapper over the same JSON messages. The agent entry is the CLI and the Unix socket.

Request:

```json
{"tool":"probe","code":"return 1;","timeout_ms":5000}
```

Response:

```json
{"status":"passed","duration_ms":3,"failures":[],"value":1}
```

## Warm workers

`vendor/bin/probe` starts `php artisan probe:work` on the first call and reuses it after that; a bare snippet then travels over `nc` with no PHP process on the client side (see Quick start). The worker is a normal Artisan process that boots Laravel once, then reads one JSON object per Unix-socket connection.

State lives in `storage/framework/probe/` (`active.json`, `session.json`, the socket). Override the location with `PROBE_HOME` and `PROBE_SOCKET` in `.env` so the thin client and Artisan agree. The supervisor lock stops two clients from booting two workers.

The pool size is `PROBE_WORKERS` (default 1). Agent tools enter through worker 0 so the last result stays in one `session.json`: `PROBE_WORKERS` does not parallelize plain probes, it sets the number of PHPUnit shards for `run_affected`. `Probe\Selective\ShardPlanner` packs files by the durations in `durations.json` (longest first). With one worker, or one file, there is a single shard. The supervisor restarts a worker that dies, and recycles it after `recycle_after` probes (default 500).

Each probe runs in a forked child of the worker (`fork`, `PROBE_FORK=false` to disable). Config changes, faked facades, `Carbon::setTestNow`, a logged-in user, a fatal error or an `exit()` die with the child, and the worker keeps the state it booted with. Without `pcntl` and `posix` probes run inside the worker; `--status` and the ping then say `isolation: process` with a notice.

The worker watches the files it loaded at boot (everything outside `vendor`, plus `.env` and `composer.lock`). When one changes, the next call is answered by a fresh worker and carries `recycled: <file>`. Code loaded only inside probes is read again by each child. `vendor` is not watched: run `--cold` after `composer update`.

`--cold` stops the current worker first. `--stop` shuts it down.

### OPcache and preload

The hot path is the already-booted process: files stay loaded, so the next probe does not compile the framework again.

On top of that, the supervisor starts workers with `opcache.enable_cli=1` and `opcache.file_cache` under the worker directory, unless `PROBE_OPCACHE=0`. The worker also calls `Probe\Support\Preloader` at boot, which `opcache_compile_file`s the package classes plus any paths in `config('probe.preload')`.

For PHP-FPM style preloading, point `opcache.preload` at `vendor/gitpcl/probe/preload.php`. CLI workers do not share an FPM preload parent; the file cache is what helps the next cold start.

### SQLite memory

`probe.database` defaults to `sqlite_memory`. When `pdo_sqlite` is loaded, the worker's **default** connection becomes SQLite `:memory:`. `probe.migrate` runs migrations once on that connection at boot. `probe.isolate` wraps each probe in a transaction and rolls it back.

Named connections configured by the host app stay available. A snippet that calls `DB::connection('mysql')` talks to that connection, and those writes are not rolled back.

Set `PROBE_DATABASE=host` to keep the application's default connection.

`probe.prepare` is optional PHP run once at worker boot, after migrations and outside the per-probe transaction. Use it to create tables that should survive for the life of the worker.

On the SQLite used here, `Schema::create` inside a probe rolls back with the transaction, so the next probe does not see that table. Put durable schema in `probe.prepare` or in migrations. Some DDL statements commit immediately on other SQLite builds, so do not rely on either outcome from inside a probe.

## Browser

`probe_browser` is a smoke check: fetch a page, assert `--contains` and `--absent` text and `--http-status`, and run `goto`, `fill` and `click` steps. Both engines render client-side apps after `domcontentloaded`, so text that only exists in a Vue template is visible. It takes no screenshots, captures no console output, reports no timings and carries no session or auth. It is not UI testing.

Engines: Lightpanda (`lightpanda fetch --dump html <url>`) or Playwright through `resources/playwright-probe.mjs`, which needs Node and the `playwright` npm package installed in the app (`npm install -D playwright`); the package is resolved from the project root. `engine: auto` uses the persistent Playwright browser when Node and the package exist, then Lightpanda on `PATH`, then the one-shot Playwright script. Set `PROBE_BROWSER=playwright` to force Playwright, or `PROBE_LIGHTPANDA` / `PROBE_PLAYWRIGHT` to an absolute binary or script. Steps always go to Playwright. Lightpanda does not report the HTTP status, so `--http-status` on it returns `status: unsupported`. If neither engine exists, the result is `status: error` with `id: missing_tool` and a message naming what is missing.

With Node and the `playwright` package in the app, one browser stays alive next to the worker (`browser.persistent`, default true): the first call launches it (300 to 600 ms, measured), later calls open a fresh context in that browser (about 55 ms in the worker, about 100 ms wall through the shell entry); `value.persistent` and `value.pid` say so, on errors too. Each probe gets its own context, closed when it ends, so a page that timed out on a selector cannot leak into the next call and the browser stays up; the process is replaced only when it dies or stops answering. `PROBE_BROWSER_PERSISTENT=false` goes back to one process per call, which is what Lightpanda always is (about 300 ms, almost all of it launch). The client waits for `browser.timeout_ms` (default 8 s) before giving up.

URLs must be `http` or `https`. A path such as `/login` is joined to `PROBE_BASE_URL` or `APP_URL`. `--contains` and `--absent` are exact substring checks. A green browser result is `failures: []` plus `value.engine`.

## Affected tests

`run_affected --paths=app/Models/User.php` builds a file graph under the project (it skips `vendor`):

- the changed file itself when it is a test
- tests that reference a class defined in the changed file, or any project class that reaches it through up to `selective.depth` hops (default 4) of static references in `app/` and `database/`: imports, `new`, static calls, type hints
- tests that call a route (quoted URI or route name) whose controller is in that set, resolved from the booted router inside the worker
- tests whose filename contains the source stem (`UserController.php` also matches `User`)

The graph is static: it follows names in code, not container bindings, events, string class names or reflection. A test that reaches a change only through one of those is not selected, so a green `run_affected` means "the tests the graph can see pass", not "this change is safe". Treat it as a fast first signal and run the suite before calling a change safe; coverage-map selection (pcov or Xdebug) is the follow-up that closes that gap.

Every result says what ran: `duration_ms` is wall time and `value.shard_ms` the sum over shards, `value.files` counts the test files, `value.selection` is `affected` (the graph picked), `all` (the whole suite ran) or `given` (the caller listed tests), and `value.selected` lists the files relative to the project for `affected` and `given` only, since a full run would just repeat the suite. A failing test's message is the assertion plus the test's own frames (`at tests/Feature/UserTest.php:4`); frames under `vendor/` or outside the project are cut, so a shard forked from the worker does not ship the worker's stack. When the whole suite is estimated under `selective.full_suite_under_ms` (default 15 s, from recorded durations with 100 ms for a file without one) the whole suite runs instead of a selection, because selecting would then save less than it can hide. `PROBE_FULL_SUITE_UNDER_MS=0` keeps graph selection only. `mutate_check` and `auto_probe` use the same selection.

Empty `--paths` uses `git diff --name-only HEAD` plus untracked files. No paths and no diff is an error. No matching tests is a pass with `value.ran` of 0.

The runner is `vendor/bin/pest` when it exists, otherwise `vendor/bin/phpunit`. Override it with `PROBE_TEST_RUNNER`. Output is read from JUnit, not from the decorated terminal. Shards run as forked children of the warm worker (`selective.mode` `fork`, the default where `pcntl` exists): a child inherits Composer, the compiled framework and OPcache, drops the worker's `.env` values, and includes the runner in-process with `--no-output`, so no shard pays PHP startup, Composer or a boot. `PROBE_SELECTIVE_MODE=process` starts one runner process per shard instead. `PROBE_SELECTIVE_WORKERS` sets the shard count; the default is one per CPU, capped by the file count. `value.mode` says which ran.

## Quality

`mutate_check --file=app/Support/Money.php` applies a few one-token flips (`+`/`-`, comparisons, `true`/`false`) and reruns the affected tests. The original file is restored afterwards, including on shutdown. A survivor is a normal failure: `survived + to - at app/Support/Money.php:12`. If the tests already fail, or none cover the file, the tool returns an error instead of a fake score. Default limit is 8.

`property_check` runs the snippet once per generated case. `$input`, `$a`, and `$b` are in scope. The snippet must return `true`. Built-in names: `addition_commutative`, `strlen_reverse`, `sort_idempotent`. `--cases` and `--seed` keep the sample deterministic. A failing case is one compact failure that includes the case index and the input.

`architecture_check` reads `probe.architecture.rules`:

```php
'rules' => [
    ['path' => 'app/Http/Controllers', 'forbid_use' => ['Illuminate\Support\Facades\DB']],
],
```

It also runs `*Test.php` files under `tests/Architecture` and `tests/Arch` through Pest or PHPUnit, which is the path for Pest `arch()` tests. No rules and no files is an error, so a misconfigured check does not look green.

## Formal checks

`formal_check --file=specs/auth.als` picks the bridge from the extension (`.als`, `.tla`, `.dfy`, `.lean`) or from `--bridge=alloy|tla|dafny|lean`. Binaries default to `alloy`, `tlc`, `dafny`, and `lean`. Override with `PROBE_ALLOY`, `PROBE_TLA`, `PROBE_DAFNY`, and `PROBE_LEAN`.

A missing binary is `missing_tool`. A counterexample is `status: failed` with a short message. `get_failures` adds the prover trace. Inline specs can be passed as `code` with a `bridge`.

## Static analysis

`static_check --paths=app/Services/Pricing.php` runs PHPStan (Larastan is a PHPStan extension and needs nothing extra here) over the changed files and every file that references them through `use`, `new`, static calls and type hints, up to `selective.depth` hops: the set a signature change can break. `--scope=changed` analyses only the given files, `--scope=all` leaves the paths to `phpstan.neon`. No paths means the git diff.

```json
{"status":"failed","duration_ms":2140,"failures":[{"id":"1","message":"Parameter #1 $cents of method total() expects int, string given. at app/Http/Controllers/CartController.php:31"}],"value":{"scope":"affected","errors":1,"files":3}}
```

`get_failures` adds `file` and `line`. The project's `phpstan.neon` owns the level, baseline and extensions; `--level` overrides it for one run, and `static.level` (default 5) applies only when there is no neon. The binary is `vendor/bin/phpstan` or `PROBE_PHPSTAN`; missing is `missing_tool` with the install line (`composer require --dev larastan/larastan`). PHPStan's own result cache makes repeat runs fast; Probe keeps no daemon for it, since none exists outside PHPStan Pro.

## TypeScript

`type_check` runs `node_modules/.bin/vue-tsc`, else `tsc`, with `--noEmit -p tsconfig.json` (`--project` or `PROBE_TSCONFIG` for another file). The compiler decides what it checks from that file, so `--paths` only filters the report: `value.errors` is what is shown and `value.total` the whole count, so a filter never hides a broken caller.

```json
{"status":"failed","duration_ms":6800,"failures":[{"id":"1","message":"TS2322: Type 'string' is not assignable to type 'number'. at resources/js/Pages/Cart.vue:14"}],"value":{"errors":1,"total":1,"binary":"vue-tsc","project":"tsconfig.json"}}
```

A missing binary is `missing_tool` (`npm install --save-dev typescript vue-tsc`); a missing tsconfig is `error` `tsconfig`. tsc's incremental mode does the caching.

## Contracts

`contract_check` reads the OpenAPI 3 document (`openapi.yaml` or `.json` in the root, `docs/`, `public/`, or `storage/api-docs/api-docs.json`; `contract.spec` or `PROBE_OPENAPI` names another; YAML needs `symfony/yaml`, which Laravel ships) and sends each documented operation through the booted application as a probe: `Accept: application/json`, path and query parameters from their `example`s, the request body from the `requestBody` example, inside the probe's transaction so writes roll back. The lowest `2xx` `application/json` schema is the contract; the response is decoded and checked against it (`$ref` into `components`, `type`, `nullable`, `required`, `properties`, `additionalProperties: false`, `items`, `enum`, `allOf`/`oneOf`/`anyOf`, lengths, bounds, `pattern`; `format` is not checked).

```json
{"status":"failed","duration_ms":210,"failures":[{"id":"1","message":"GET /api/users/{id} 200: $.data.email: expected string, got null"},{"id":"2","message":"POST /api/orders: expected 201, got 500 Server Error"}],"value":{"operations":12,"checked":10,"failed":2,"selection":"all","spec":"openapi.yaml","skipped":[{"operation":"DELETE /api/orders/{id}","reason":"no example for path parameter id"}]}}
```

`--routes='GET /api/users/{id},/api/orders'` checks those operations; `--paths=app/Http/Controllers/UserController.php` checks the operations whose routes that controller, and whatever reaches it through the reference graph, serves, read from the booted router. An operation without a path example, a required-body example or a JSON `2xx` schema is listed under `skipped` rather than counted green, and a run where nothing could be sent is an error. `--auth=<user id or email>` (or `contract.auth`) logs that user in on the default guard first; `contract.headers` go on every request; `contract.limit` (default 10) caps messages per operation.

## Auto probes

`auto_probe` ranks impacted tests with `Probe\Intelligence\HeuristicSelector` (direct matches, then recent failures in `failure-history.json`) and runs at most `PROBE_AUTO_LIMIT` of them (default 8). If nothing matches, it probes `class_exists` for classes in the changed files.

Set `PROBE_LLM=true` and `PROBE_LLM_ENDPOINT` to ask `Probe\Intelligence\LlmSelector` first. It expects JSON `{"tests":["..."]}`. If the call is disabled, unconfigured, or fails, the heuristic selector runs and the result says `"selector":"heuristic"`. Tests and CI leave the LLM off, so no API key is required.

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
| `shell_median_ms` | The shell entry over `nc` against the warm worker, what a bare snippet pays; absent when `nc` is missing |
| `roundtrip_median_ms` | Socket round trip from an already running client |
| `met_target` | `process_median_ms` is under 150 |

Measured on this package's Testbench fixture (Laravel 12 application skeleton, PHP 8.3.6, `vendor/bin/probe --benchmark --samples=20`):

```json
{"status":"passed","cold_ms":87,"process_median_ms":22,"process_min_ms":21,"process_p95_ms":24,"roundtrip_median_ms":0.257,"roundtrip_min_ms":0.142,"samples":20,"target_ms":150,"met_target":true,"php":"8.3.6"}
```

`duration_ms` inside the worker rounded to 0 for `return 1;`. The warm target is the fresh PHP process against an already warm worker (`process_median_ms`), which was 22 ms here. Cold boot on this small app was 87 ms. A larger application spends more time booting the worker. Re-run the command in that app. The integer `duration_ms` field stays whole milliseconds so a green probe stays small.

### A real application

A production Laravel application (Laravel 12.56, 125 Pest tests, PHP 8.4.25, Apple silicon with 12 performance and 4 efficiency cores), measured on 2026-10-21 by a session independent of the one that wrote the code, through the Composer-installed proxies. Medians, run count in the last column; wall times include the measuring script's own 5 ms. The 0.6.0 baseline from the same machine: 38 ms per probe through the PHP client, 1938 ms for the suite in one Pest process, about 300 ms per browser call.

One check, `Setting::count()` on a fresh in-memory database:

| How | Median | Runs |
| --- | --- | --- |
| Probe over MCP, session already open | 2.5 ms (p95 4.0) | 10 |
| Probe shell entry, `vendor/bin/probe` bare snippet | 25 ms | 10 |
| Probe PHP client, `--tool=probe` | 48 ms | 10 |
| PHPUnit, one test with `RefreshDatabase` | 128 ms | 5 |
| Pest, one test with `RefreshDatabase` | 157 ms | 5 |

Pest and PHPUnit pay PHP startup, Composer autoload, the Laravel boot and the migration on every run. Probe pays that once at worker boot (135 to 260 ms across tonight's cold starts). Composer's generated `vendor/bin/probe` proxy adds about 8 ms (`realpath` and two subshells before the exec): the script itself, `vendor/gitpcl/probe/bin/probe`, answered in 17 ms through the same timer, and `--benchmark` times that script, not the proxy. An agent that calls the package script directly gets the lower number.

Whole suite, 125 tests and 0 failures on every run:

| How | Median | Range | Runs |
| --- | --- | --- | --- |
| Probe `run_affected`, 16 forked shards | 993 ms | 980 to 1365 | 5 |
| `pest --parallel` | 1570 ms | 1566 to 1708 | 5 |
| `pest` | 2046 ms | 2021 to 2208 | 5 |

The 1365 is the first run after a cold worker. The floor is the two slowest test files at about 750 ms each: a shard is as slow as its slowest file, so shard count, OPcache and a persistent runner cannot push the suite much under one second here. PHPUnit has no row because this suite uses Pest's functions.

Selection and browser:

| Path | Result | Runs |
| --- | --- | --- |
| One service change through `run_affected`, guard off | 6 tests in 94 to 137 ms, the stability test found through the route hop | 2 |
| Browser check, first call | 619 ms, 291 ms in an earlier run; the launch dominates and varies | 1 each |
| Browser check, kept browser | 101 to 104 ms wall, 55 to 57 ms inside the worker, same pid | 4 |

A probe is a throwaway check, not a test file that stays in the repo: the speed is for the agent's inner loop, and the suite still runs before a change is called safe. Agent loops take the MCP server or the shell entry. `run_affected` shows what it selected and, when the guard says the whole suite is cheap, runs it in about the time of the slowest file. `static_check`, `type_check` and `contract_check` are bound by the tool they wrap: PHPStan's result cache and tsc's incremental mode do the caching, and what Probe adds is the selection (the files a change touches, the routes a controller change reaches) and the result shape.

## Configuration

Published to `config/probe.php`.

| Key | Default | Role |
| --- | --- | --- |
| `database` | `sqlite_memory` | `sqlite_memory` or `host` |
| `migrate` | `true` | Migrate the probe connection at worker boot |
| `prepare` | `null` | PHP run once at boot, outside the per-probe transaction |
| `isolate` | `true` | Roll back the default connection after each probe |
| `fork` | `true` | Run each probe in a forked child so nothing it changes survives; needs `pcntl` and `posix` |
| `workers` | `1` | Pool size |
| `timeout_ms` | `5000` | Per-probe wall clock. `pcntl` alarms at one-second resolution |
| `recycle_after` | `500` | Probes before the worker exits |
| `max_value_bytes` | `1024` | Largest returned value kept inline |
| `preload` | `[]` | Extra files to compile at boot |
| `opcache` | `true` | Pass OPcache file-cache flags to the worker |
| `browser.engine` | `auto` | `auto`, `lightpanda`, or `playwright` |
| `browser.persistent` | `true` | Keep one Playwright browser alive next to the worker; `PROBE_BROWSER_PERSISTENT` |
| `selective.runner` | Pest, else PHPUnit | Override with `PROBE_TEST_RUNNER` |
| `selective.full_suite_under_ms` | 15000 | Run the whole suite when it is estimated under this; `PROBE_FULL_SUITE_UNDER_MS`, 0 keeps graph selection |
| `selective.mode` | `fork` | Shards as forked worker children, or `process` for one runner process per shard; `PROBE_SELECTIVE_MODE` |
| `selective.depth` | `4` | Hops of static references a change follows to find impacted classes; `PROBE_SELECTIVE_DEPTH`, 0 for one hop |
| `mutate.limit` | `8` | Operator flips per `mutate_check` |
| `property.cases` | `20` | Generated cases per property |
| `architecture.rules` | `[]` | `path` plus `forbid_use` list |
| `formal.*` | `alloy`, `tlc`, `dafny`, `lean` | Optional prover binaries |
| `static.binary` | `vendor/bin/phpstan` | `PROBE_PHPSTAN`; missing is `missing_tool` |
| `static.level` | `5` | Only when the project has no `phpstan.neon`; `PROBE_PHPSTAN_LEVEL` |
| `types.binary` | `vue-tsc`, else `tsc`, under `node_modules/.bin` | `PROBE_TSC` |
| `types.project` | `tsconfig.json` | `PROBE_TSCONFIG` |
| `contract.spec` | searched | `PROBE_OPENAPI`; else `openapi.{yaml,json}` in the root, `docs/`, `public/`, `storage/api-docs` |
| `contract.auth` | `null` | User id or email logged in before each request; `PROBE_CONTRACT_AUTH` |
| `intelligence.llm` | `false` | Heuristic selector unless an endpoint is set |

Timeouts need `ext-pcntl`. With `fork` on, a tight CPU loop is killed with its child half a second after the timeout and the worker stays up. Without it the loop may run until the client gives up and the supervisor kills the worker; the next call boots a fresh one. The client waits at least the tool's own timeout (browser, selective, formal, static, types, contract) before reporting `client timed out`.

## Security

Probe executes arbitrary PHP inside the application. It is a local dev dependency. Do not expose the socket, do not install it with Composer in production (`--dev` keeps it out of `--no-dev` installs), and do not point it at untrusted snippets. `mutate_check` rewrites the target file until the run finishes. Browser, formal, static and type tools shell out to local binaries; `contract_check` sends requests through the booted app inside the probe's transaction.

## Scope

Shipped: warm workers, compact JSON, Lightpanda/Playwright browser probes, affected-test selection with duration sharding, scoped mutation, property checks, architecture rules, Alloy/TLA+/Dafny/Lean bridges, heuristic auto-probes, PHPStan over affected files, vue-tsc/tsc, and OpenAPI contract checks against the booted app.

Still local-only: Unix sockets (no Windows), MCP as a separate wrapper, and a live model call only when `PROBE_LLM` is set. External binaries are detected at runtime and degrade to `missing_tool`.

## Development

```bash
composer test      # PHPUnit
composer lint      # Pint, check only
composer analyse   # PHPStan, level 6
composer check     # all three
```

CI runs the suite on PHP 8.2, 8.3 and 8.4 against Laravel 12 and 13, plus Pint and PHPStan on PHP 8.4. Format a file you changed with `vendor/bin/pint <path>`.

## License

MIT.
