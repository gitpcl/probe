# Probe
## AI-Native Testing Runtime for Laravel

**Status**: Phases 1 to 5 are implemented in this repository. This file is the original design. The README describes the commands that ship.
**Goal**: A Composer package that makes AI coding agents the primary user of the test suite. Agents can probe changes, find bugs, and re-verify in a tight loop measured in tens of milliseconds to a few seconds, while still supporting traditional human-written tests and formal verification.

## Progress

| Phase | Shipped |
| --- | --- |
| 1 | Warm PHP workers, `probe`, `get_failures`, `rerun_failed`, compact JSON |
| 2 | `probe_browser` (Lightpanda, Playwright fallback), `run_affected`, duration-aware `ShardPlanner` |
| 3 | `mutate_check`, `property_check`, `architecture_check` |
| 4 | `formal_check` for Alloy, TLA+, Dafny, and Lean 4. Counterexamples are normal failures |
| 5 | `auto_probe` via `HeuristicSelector`. `LlmSelector` is off unless `PROBE_LLM` and an endpoint are set |
| 6 | `static_check` (PHPStan over affected files), `type_check` (vue-tsc, else tsc), `contract_check` (OpenAPI responses against the booted app) |

---

## 1. Problem Statement

Pest and PHPUnit were designed for humans:
- Verbose, decorated terminal output
- Cold process starts on every run
- No first-class concept of a throwaway verification probe
- Browser tests are too slow for agents that re-run constantly

AI agents need the opposite:
- Compact, structured results
- Sub-second feedback on small changes
- Ability to run the same verification dozens of times while iterating
- Optional formal guarantees on critical properties
- Extremely fast browser checks

---

## 2. Core Design Principles

1. **Agent is the primary user**
2. **Probes over full suites**
3. **Warm by default**
4. **Structured, constant-size results**
5. **Formal methods as a deeper layer**
6. **Hybrid browser engines**
7. **Full compatibility with existing Pest/PHPUnit tests**

---

## 3. High-Level Architecture

Agent (Claude Code, Cursor, etc.) → MCP/RPC → Probe Agent Interface → Warm PHP Workers + Selective Execution + Formal Bridge → Execution Backends (Pest/PHPUnit, Lightpanda/Playwright, SQLite memory)

---

## 4. Agent Interface (MCP Tools)

- `probe` — Throwaway PHP snippet in full Laravel context
- `probe_browser` — Browser flow
- `run_affected` — Only tests impacted by change
- `rerun_failed` — Re-execute last failures
- `get_failures` — Progressive detail
- `formal_check` — Alloy / TLA+ / Dafny / Lean
- `static_check` — PHPStan / Larastan over the files a change touches
- `type_check` — vue-tsc / tsc
- `contract_check` — OpenAPI operations through the booted app
- `mutate_check` / `property_check` — Quality signals

Target latencies: 30–150 ms for PHP probes, sub-second for light browser probes.

---

## 5. Performance Strategy

- Warm PHP worker pool with OPcache + preload
- Selective execution (TIA-style dependency graph)
- Default SQLite :memory:
- Hybrid browser: Lightpanda (default) / Playwright fallback; both about 300 ms per call on a small local page, almost all of it browser launch
- Duration-aware sharding and parallel workers
- Compact structured JSON output

---

## 6. Formal Methods & Quality Layers

- Alloy, TLA+, Dafny, Lean 4 bridges
- Mutation testing (scoped)
- Property-based / metamorphic testing
- Architecture tests

Counterexamples become ordinary structured failures.

---

## 7. Package Shape

```bash
composer require your-vendor/probe --dev
php artisan probe:install
```

Config in `config/probe.php`. Existing Pest/PHPUnit tests continue to work.

---

## 8. Implementation Phases

1. Agent core + warm workers + structured output
2. Hybrid browser + selective execution
3. Quality layer (mutation, property, architecture)
4. Formal bridges
5. Intelligence (auto-probes, model-based selection)

---

## 9. Success Criteria

- Median PHP probe < 150 ms after warm-up
- Non-visual browser probe < 500 ms on light engine
- Passing output under ~40 tokens
- Existing suites remain fully compatible
- Formal counterexamples appear as actionable failures

---

**Name**: Probe  
**Language**: PHP (core)  
**Primary user**: AI coding agents
