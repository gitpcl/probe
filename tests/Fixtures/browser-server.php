#!/usr/bin/env php
<?php

declare(strict_types=1);

// A persistent browser stand-in: one JSON line in, one out, until stdin closes.
while (($line = fgets(STDIN)) !== false) {
    $input = json_decode($line, true);
    $url = is_array($input) ? (string) ($input['url'] ?? '') : '';

    if (str_contains($url, '/die')) {
        exit(1);
    }

    if (str_contains($url, '/slow')) {
        usleep(3_000_000);
    }

    if (str_contains($url, '/ansi')) {
        echo json_encode(['error' => "page.click: Timeout 8000ms exceeded. Call log: \x1b[2m - waiting for locator\x1b[22m", 'pid' => getmypid()]), "\n";
        fflush(STDOUT);

        continue;
    }

    echo json_encode([
        'http_status' => str_contains($url, '/nope') ? 404 : 200,
        'body' => '<html><body>Example Domain from pid '.getmypid().'</body></html>',
        'pid' => getmypid(),
    ]), "\n";
    fflush(STDOUT);
}
