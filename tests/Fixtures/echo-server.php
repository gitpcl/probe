<?php

declare(strict_types=1);

use Probe\Worker\Server;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$socket = $argv[1] ?? '';
if ($socket === '') {
    fwrite(STDERR, "socket path required\n");
    exit(1);
}

$server = new Server;
$server->listen($socket, function (array $request) use ($server): array {
    if (($request['tool'] ?? '') === 'shutdown') {
        $server->stop();

        return ['status' => 'passed', 'duration_ms' => 0, 'failures' => []];
    }

    return [
        'status' => 'passed',
        'duration_ms' => 1,
        'failures' => [],
        'value' => $request['code'] ?? null,
    ];
});
