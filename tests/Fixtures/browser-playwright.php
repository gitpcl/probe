#!/usr/bin/env php
<?php

declare(strict_types=1);

$raw = stream_get_contents(STDIN);
$data = json_decode(is_string($raw) ? $raw : '{}', true);
$url = is_array($data) ? (string) ($data['url'] ?? '') : '';
$body = str_contains($url, 'missing-text') ? 'nope' : 'Example Domain';

echo json_encode([
    'http_status' => 200,
    'body' => '<html>'.$body.'</html>',
], JSON_UNESCAPED_SLASHES);
