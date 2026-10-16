<?php

declare(strict_types=1);

use Probe\Runner\SnippetSource;

/**
 * Runs a probe snippet from the global namespace so facade aliases and
 * leading use imports resolve the way they do in a normal PHP file.
 * The included file is generated, invalidated for OPcache, and deleted.
 */
function probe_execute_snippet(string $code): mixed
{
    $code = preg_replace('/^\s*<\?(?:php)?/i', '', $code) ?? $code;
    $code = preg_replace('/\?>\s*$/', '', $code) ?? $code;

    if (preg_match('/^\s*namespace\s+/m', $code) === 1) {
        throw new InvalidArgumentException('Probe snippets are statements inside the application. A namespace declaration is rejected. Use a leading use import or a fully qualified class name.');
    }

    [$uses, $body] = probe_hoist_uses($code);

    $prefix = "<?php\n";
    if ($uses !== []) {
        $prefix .= implode("\n", $uses)."\n";
    }
    $prefix .= "return (static function () {\n";

    SnippetSource::$lineOffset = substr_count($prefix, "\n");
    SnippetSource::$code = $body;

    $contents = $prefix.$body."\n})();\n";
    $path = sys_get_temp_dir().'/probe-snippet-'.getmypid().'-'.bin2hex(random_bytes(4)).'.php';

    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Probe could not write a snippet file.');
    }

    @chmod($path, 0600);

    try {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        return include $path;
    } finally {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/**
 * @return array{0: list<string>, 1: string}
 */
function probe_hoist_uses(string $code): array
{
    $uses = [];
    $body = [];
    $hoisting = true;

    foreach (preg_split("/\r\n|\n|\r/", $code) ?: [] as $line) {
        $trim = trim($line);
        if ($hoisting && ($trim === '' || str_starts_with($trim, '//') || str_starts_with($trim, '#'))) {
            continue;
        }

        if ($hoisting && preg_match('/^use\s+/', $trim) === 1) {
            if (! str_ends_with($trim, ';')) {
                throw new InvalidArgumentException('A probe use import must be one line ending with a semicolon.');
            }

            $uses[] = $trim;

            continue;
        }

        $hoisting = false;
        $body[] = $line;
    }

    return [$uses, implode("\n", $body)];
}
