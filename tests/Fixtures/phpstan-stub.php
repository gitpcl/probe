#!/usr/bin/env php
<?php

declare(strict_types=1);

// Stands in for vendor/bin/phpstan: records its arguments, answers in PHPStan's JSON shape.

$log = getenv('PROBE_STUB_LOG');
if (is_string($log) && $log !== '') {
    file_put_contents($log, implode("\n", array_slice($argv, 1))."\n", FILE_APPEND | LOCK_EX);
}

$sleep = getenv('PROBE_STUB_SLEEP_MS');
if (is_string($sleep) && $sleep !== '') {
    usleep((int) $sleep * 1000);
}

if (getenv('PROBE_STUB_PHPSTAN') === 'crash') {
    fwrite(STDERR, "PHP Fatal error:  Allowed memory size of 134217728 bytes exhausted\n");
    exit(255);
}

$files = [];
$errors = getenv('PROBE_STUB_ERRORS');
if (is_string($errors) && $errors !== '') {
    foreach (explode(';', $errors) as $row) {
        [$file, $line, $message] = array_pad(explode('|', $row, 3), 3, '');
        $files[$file]['errors'] = ($files[$file]['errors'] ?? 0) + 1;
        $files[$file]['messages'][] = [
            'message' => $message,
            'line' => $line === '' ? null : (int) $line,
            'ignorable' => true,
            'identifier' => 'stub.error',
        ];
    }
}

$total = array_sum(array_column($files, 'errors'));
fwrite(STDOUT, json_encode([
    'totals' => ['errors' => 0, 'file_errors' => $total],
    'files' => $files === [] ? new stdClass : $files,
    'errors' => [],
]));
exit($total > 0 ? 1 : 0);
