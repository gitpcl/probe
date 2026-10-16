<?php

declare(strict_types=1);

namespace Probe\Result;

final class JsonResultEncoder
{
    public function encode(ProbeResult $result, bool $detailed = false): string
    {
        $payload = $detailed ? $this->detailed($result) : $this->compact($result);

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    public function compact(ProbeResult $result): array
    {
        return $this->shape($result, false);
    }

    /** @return array<string, mixed> */
    public function detailed(ProbeResult $result): array
    {
        return $this->shape($result, true);
    }

    /** @return array<string, mixed> */
    private function shape(ProbeResult $result, bool $detailed): array
    {
        $payload = [
            'status' => $result->status,
            'duration_ms' => $result->durationMs,
            'failures' => array_map(
                fn (Failure $failure): array => $detailed ? $failure->detailed() : $failure->compact(),
                $result->failures,
            ),
        ];

        if ($result->hasValue) {
            $payload['value'] = $result->value;
        }

        if ($result->tool !== null) {
            $payload['tool'] = $result->tool;
        }

        if ($result->phase !== null) {
            $payload['phase'] = $result->phase;
        }

        if ($result->rerun !== null) {
            $payload['rerun'] = $result->rerun;
        }

        return $payload;
    }
}
