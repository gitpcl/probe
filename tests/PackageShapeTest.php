<?php

declare(strict_types=1);

namespace Probe\Tests;

use PHPUnit\Framework\TestCase;
use Probe\Contract\BrowserProbe;
use Probe\Contract\FormalBridge;
use Probe\Contract\QualityCheck;
use Probe\Contract\SelectiveRunner;

final class PackageShapeTest extends TestCase
{
    public function test_composer_manifest_matches_the_package_contract(): void
    {
        $json = json_decode((string) file_get_contents(dirname(__DIR__).'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('gitpcl/probe', $json['name']);
        $this->assertSame('MIT', $json['license']);
        $this->assertSame('src/', $json['autoload']['psr-4']['Probe\\']);
        $this->assertContains('Probe\\ProbeServiceProvider', $json['extra']['laravel']['providers']);
        $this->assertContains('bin/probe', $json['bin']);
        $this->assertSame('library', $json['type']);
    }

    public function test_later_phases_have_interfaces_and_no_implementations_yet(): void
    {
        $this->assertTrue(interface_exists(BrowserProbe::class));
        $this->assertTrue(interface_exists(SelectiveRunner::class));
        $this->assertTrue(interface_exists(FormalBridge::class));
        $this->assertTrue(interface_exists(QualityCheck::class));
    }
}
