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
    # A Node crash: the cause sits on top, the frames below (the shape of vue-tsc on an unsupported TypeScript).
    cat >&2 <<'TRACE'
node:internal/modules/cjs/loader:1228
  throw err;
  ^

Error [ERR_PACKAGE_PATH_NOT_EXPORTED]: Package subpath './lib/tsc' is not defined by "exports" in /app/node_modules/typescript/package.json
    at exportsNotFound (node:internal/modules/esm/resolve:304:10)
    at packageExportsResolve (node:internal/modules/esm/resolve:651:9)
    at resolveExports (node:internal/modules/cjs/loader:591:36)
    at Module._findPath (node:internal/modules/cjs/loader:668:31)
    at Module._resolveFilename (node:internal/modules/cjs/loader:1130:27)
    at Module._load (node:internal/modules/cjs/loader:985:27)
    at Module.require (node:internal/modules/cjs/loader:1235:19)
    at require (node:internal/modules/helpers:176:18)
    at Object.<anonymous> (/app/node_modules/vue-tsc/index.js:44:45)
    at Object.run (/app/node_modules/vue-tsc/index.js:64:20) {
  code: 'ERR_PACKAGE_PATH_NOT_EXPORTED'
}
TRACE
    exit 9
fi

if [ -n "$PROBE_STUB_TS" ]; then
    printf '%b\n' "$PROBE_STUB_TS"
    exit 2
fi

printf '%s\n' "Found 0 errors."
exit 0
