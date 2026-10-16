<?php

declare(strict_types=1);

namespace Probe\Tests;

use PHPUnit\Framework\TestCase;
use Probe\Browser\HybridBrowser;
use Probe\Contract\BrowserProbe;
use Probe\Contract\FormalBridge;
use Probe\Contract\QualityCheck;
use Probe\Contract\SelectiveRunner;
use Probe\Formal\SpecRunner;
use Probe\Intelligence\HeuristicSelector;
use Probe\Intelligence\Selector;
use Probe\Quality\ArchitectureCheck;
use Probe\Quality\MutationCheck;
use Probe\Quality\PropertyCheck;
use Probe\Selective\AffectedRunner;

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

    public function test_phase_tools_implement_the_contracts(): void
    {
        $this->assertContains(BrowserProbe::class, class_implements(HybridBrowser::class));
        $this->assertContains(SelectiveRunner::class, class_implements(AffectedRunner::class));
        $this->assertContains(FormalBridge::class, class_implements(SpecRunner::class));
        $this->assertContains(QualityCheck::class, class_implements(MutationCheck::class));
        $this->assertContains(QualityCheck::class, class_implements(PropertyCheck::class));
        $this->assertContains(QualityCheck::class, class_implements(ArchitectureCheck::class));
        $this->assertContains(Selector::class, class_implements(HeuristicSelector::class));
    }
}
