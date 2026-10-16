#!/usr/bin/env php
<?php

declare(strict_types=1);

$url = $argv[count($argv) - 1] ?? '';
if (str_contains($url, 'missing-text')) {
    echo '<html>nope</html>';
    exit(0);
}

echo '<html>Example Domain</html>';
