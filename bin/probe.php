#!/usr/bin/env php
<?php

declare(strict_types=1);

// stdout is one JSON object for the agent; PHP's own messages go to stderr.
ini_set('display_errors', 'stderr');

use Probe\Console\Dispatcher;

// The thin client loads Probe's own classes and nothing else. The application's
// Composer autoloader would add its helper files to every call (about 8 ms) and
// no class on the client path needs it: the worker is where Laravel lives.
spl_autoload_register(static function (string $class): void {
    if (! str_starts_with($class, 'Probe\\')) {
        return;
    }

    $file = __DIR__.'/../src/'.strtr(substr($class, 6), '\\', '/').'.php';
    if (is_file($file)) {
        require $file;
    }
});

exit(Dispatcher::main($argv));
