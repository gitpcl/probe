#!/usr/bin/env php
<?php

declare(strict_types=1);

$junit = null;
$files = [];

foreach ($argv as $index => $arg) {
    if ($arg === '--log-junit') {
        $junit = $argv[$index + 1] ?? null;
        continue;
    }

    if (is_string($arg) && str_ends_with($arg, '.php') && ! str_contains($arg, 'phpunit-stub.php')) {
        $files[] = $arg;
    }
}

$fail = getenv('PROBE_STUB_FAIL') === '1';
foreach ($files as $file) {
    if (str_contains(basename($file), 'Fail')) {
        $fail = true;
    }
}

$watch = getenv('PROBE_STUB_WATCH');
if (is_string($watch) && $watch !== '' && is_file($watch)) {
    $source = (string) file_get_contents($watch);
    if (str_contains($source, ' - ')) {
        $fail = true;
    }
}

$log = getenv('PROBE_STUB_LOG');
if (is_string($log) && $log !== '') {
    file_put_contents($log, implode("\n", $files)."\n", FILE_APPEND | LOCK_EX);
}

$fileAttribute = htmlspecialchars($files[0] ?? '', ENT_QUOTES);
$failure = $fail ? '<failure message="expected 1">expected 1</failure>' : '';
$failures = $fail ? '1' : '0';
$xml = '<?xml version="1.0"?><testsuites><testsuite tests="1" failures="'.$failures.'"><testcase name="test_it" classname="UserTest" file="'.$fileAttribute.'" time="0.01">'.$failure.'</testcase></testsuite></testsuites>';

if (is_string($junit) && $junit !== '') {
    file_put_contents($junit, $xml);
}

exit($fail ? 1 : 0);
