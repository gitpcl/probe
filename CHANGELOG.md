# Changelog

Notable changes to this package. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and versions follow [Semantic Versioning](https://semver.org/). Before 1.0, a minor bump may change tool output or defaults.

## [Unreleased]

### Fixed

- The compact failure line no longer spends its 180 characters on the `(Connection: ..., SQL: ...)` tail Laravel appends to a query exception, so the database's own error and hint stay visible; `get_failures` keeps the full text.

### Changed

- `probe-mcp` and the CLI client send PHP's own warnings and fatal errors to stderr, never to stdout, so a stray message cannot corrupt the JSON-RPC stream or the one-object CLI result. A warning raised while the MCP server handles a request is answered as a JSON-RPC internal error. The server writes one stderr line when it exits (`stdin closed after N messages`, or the fatal), so a client that reports a disconnect leaves a trace of why.

## [0.9.0] - 2026-11-01

### Fixed

- `run_affected` shards no longer share one test database. More than one shard runs with Laravel's parallel-testing variables, so each shard gets `<database>_test_probe_<n>`, as `php artisan test --parallel` does. On a PostgreSQL suite of 952 tests, 893 false failures came from shards running `migrate:fresh` over each other.
- A probe that calls `exit()` or dies on a fatal error now reports that, instead of pointing at a worker log that does not exist.
- A failure trace stops at the snippet boundary; the worker's own frames below it are no longer listed.
- `--tool=status`, `--tool=cold` and `--tool=stop` work on the CLI, matching the MCP tool names.
- `run_affected` with a `tests` list no longer demands `paths` or a git diff as well.

### Added

- `--tests=a.php,b.php` on the CLI for `run_affected` and `mutate_check`, matching the MCP `tests` argument.

## [0.8.2] - 2026-11-01

### Changed

- A `no such table` probe failure on a worker whose boot migration failed now starts with `boot migration failed (set PROBE_DATABASE=host or fix the migration)` and carries the boot error, instead of a bare SQL error that only `--status` explained.

## [0.8.1] - 2026-11-01

First public release.

### Added

- Warm PHP worker (`php artisan probe:work`): boots Laravel once, answers one JSON request per Unix-socket connection, forks each probe so nothing it changes survives, recycles on a changed source file or after `recycle_after` probes, and uses the OPcache file cache plus a preloader.
- Shell client `vendor/bin/probe`: a bare snippet goes to the live worker over `nc` with no PHP process on the client side; everything else runs the PHP client, which also supervises the worker.
- MCP server `vendor/bin/probe-mcp` over stdio, one long-lived process per agent session.
- Compact JSON result shape: `status`, `duration_ms`, `failures`, optional `value`; `get_failures` adds file, line and trace on demand.
- Tools: `probe`, `get_failures`, `rerun_failed`, `run_affected`, `probe_browser`, `mutate_check`, `property_check`, `architecture_check`, `formal_check`, `auto_probe`, `static_check`, `type_check`, `contract_check`, `status`, `cold`, `stop`.
- SQLite `:memory:` as the default probe connection, migrated once at boot, with a transaction rolled back after each probe.
- Affected-test selection from a static reference graph and the booted router, duration-aware sharding into forked children, and a guard that runs the whole suite when it is cheap.
- Browser probes through Lightpanda or Playwright, with one Playwright browser kept alive next to the worker.
- PHPStan over the files a change touches, `vue-tsc` or `tsc` type checks, and OpenAPI contract checks against the booted application.
- `--benchmark` reporting cold boot, warm client and socket round-trip medians.
- PHP 8.2 to 8.4, Laravel 12 and 13.
