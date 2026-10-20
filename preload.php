<?php

declare(strict_types=1);
use Probe\Support\Preloader;

/**
 * OPcache preload entry for SAPIs that honor opcache.preload.
 *
 * Probe's CLI workers also compile these files when they boot
 * (Probe\Support\Preloader). A warm worker keeps that bytecode for the
 * life of the process. opcache.file_cache, which the supervisor enables
 * by default, reuses it on the next cold start.
 *
 * php.ini:
 *   opcache.enable=1
 *   opcache.enable_cli=1
 *   opcache.preload=/absolute/path/vendor/gitpcl/probe/preload.php
 *   opcache.preload_user=www-data
 */
$autoload = dirname(__DIR__, 2).'/autoload.php';
if (! is_file($autoload)) {
    $autoload = __DIR__.'/vendor/autoload.php';
}

if (is_file($autoload)) {
    require_once $autoload;
}

if (class_exists(Preloader::class) && function_exists('opcache_compile_file')) {
    (new Preloader)->warm();
}
