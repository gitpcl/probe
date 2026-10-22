#!/bin/sh
# Stands in for vue-tsc or tsc: records its arguments, prints the diagnostics in
# PROBE_STUB_TS (newline escapes allowed) with exit 2, or a clean summary with exit 0.

if [ -n "$PROBE_STUB_LOG" ]; then
    printf '%s\n' "$@" >> "$PROBE_STUB_LOG"
fi

if [ -n "$PROBE_STUB_SLEEP_MS" ]; then
    sleep "$(awk "BEGIN { print $PROBE_STUB_SLEEP_MS / 1000 }")"
fi

if [ "$PROBE_STUB_TSC" = "crash" ]; then
    printf '%s\n' "node: bad option: --no-such-flag" >&2
    exit 9
fi

if [ -n "$PROBE_STUB_TS" ]; then
    printf '%b\n' "$PROBE_STUB_TS"
    exit 2
fi

printf '%s\n' "Found 0 errors."
exit 0
