<?php

declare(strict_types=1);

namespace Probe\Tests;

use PHPUnit\Framework\TestCase;
use Probe\Result\Failure;
use Probe\Result\JsonResultEncoder;
use Probe\Result\ProbeResult;

final class JsonResultEncoderTest extends TestCase
{
    public function test_green_result_stays_under_forty_tokens(): void
    {
        $json = (new JsonResultEncoder())->encode(ProbeResult::passed(12));

        $this->assertSame('{"status":"passed","duration_ms":12,"failures":[]}', $json);
        $this->assertLessThanOrEqual(40, (int) ceil(strlen($json) / 4));
        $this->assertStringNotContainsString("\n", $json);
        $this->assertStringNotContainsString("\e", $json);
    }

    public function test_scalar_values_are_included_and_null_is_omitted(): void
    {
        $encoder = new JsonResultEncoder();

        $this->assertSame(
            '{"status":"passed","duration_ms":1,"failures":[],"value":0}',
            $encoder->encode(ProbeResult::passed(1, 0, true))
        );
        $this->assertSame(
            '{"status":"passed","duration_ms":1,"failures":[],"value":false}',
            $encoder->encode(ProbeResult::passed(1, false, true))
        );
        $this->assertSame(
            '{"status":"passed","duration_ms":1,"failures":[]}',
            $encoder->encode(ProbeResult::passed(1, null, false))
        );
    }

    public function test_compact_failure_omits_trace_that_detail_keeps(): void
    {
        $result = ProbeResult::failed(18, new Failure(
            id: '1',
            message: 'Call to a member function on null',
            type: 'Error',
            file: 'probe.php',
            line: 4,
            trace: '#0 probe.php(4): explode()',
        ));

        $encoder = new JsonResultEncoder();
        $compact = $encoder->compact($result);
        $detailed = $encoder->detailed($result);

        $this->assertSame(
            ['id' => '1', 'message' => 'Call to a member function on null'],
            $compact['failures'][0]
        );
        $this->assertSame('Error', $detailed['failures'][0]['type']);
        $this->assertSame('probe.php', $detailed['failures'][0]['file']);
        $this->assertSame(4, $detailed['failures'][0]['line']);
        $this->assertArrayHasKey('trace', $detailed['failures'][0]);
    }

    public function test_unsupported_tool_names_its_phase(): void
    {
        $json = (new JsonResultEncoder())->encode(ProbeResult::unsupported('run_affected', 2));

        $this->assertSame(
            '{"status":"unsupported","duration_ms":0,"failures":[],"tool":"run_affected","phase":2}',
            $json
        );
    }
}
