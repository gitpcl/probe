# Changelog

Notable changes to this package. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and versions follow [Semantic Versioning](https://semver.org/). Before 1.0, a minor bump may change tool output or defaults.

## [Unreleased]

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
