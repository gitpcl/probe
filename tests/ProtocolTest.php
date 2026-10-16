<?php

declare(strict_types=1);

namespace Probe\Tests;

use PHPUnit\Framework\TestCase;
use Probe\Worker\Protocol;
use Probe\Worker\WorkerException;

final class ProtocolTest extends TestCase
{
    public function test_round_trip_preserves_an_object(): void
    {
        $encoded = Protocol::encode([
            'tool' => 'probe',
            'code' => "return 1;\n",
        ]);

        $this->assertStringEndsWith("\n", $encoded);
        $this->assertSame(1, substr_count($encoded, "\n"));

        $decoded = Protocol::decode($encoded);
        $this->assertSame('probe', $decoded['tool']);
        $this->assertSame("return 1;\n", $decoded['code']);
    }

    public function test_a_json_list_is_rejected(): void
    {
        $this->expectException(WorkerException::class);
        Protocol::decode("[1,2]\n");
    }

    public function test_invalid_json_is_rejected(): void
    {
        $this->expectException(WorkerException::class);
        Protocol::decode("{nope}\n");
    }
}
