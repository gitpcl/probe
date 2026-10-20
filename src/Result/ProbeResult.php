<?php

declare(strict_types=1);

namespace Probe\Result;

final class ProbeResult
{
    /**
     * @param  list<Failure>  $failures
     */
    public function __construct(
        public readonly string $status,
        public readonly int $durationMs,
        public readonly array $failures = [],
        public readonly mixed $value = null,
        public readonly bool $hasValue = false,
        public readonly ?string $tool = null,
        public readonly ?int $phase = null,
        public readonly ?bool $rerun = null,
    ) {}

    public static function passed(int $durationMs, mixed $value = null, bool $hasValue = false): self
    {
        return new self(
            status: 'passed',
            durationMs: $durationMs,
            failures: [],
            value: $value,
            hasValue: $hasValue,
        );
    }

    public static function failed(int $durationMs, Failure $failure): self
    {
        return new self(
            status: 'failed',
            durationMs: $durationMs,
            failures: [$failure],
        );
    }

    public static function error(string $id, string $message, int $durationMs = 0): self
    {
        return new self(
            status: 'error',
            durationMs: $durationMs,
            failures: [new Failure(id: $id, message: $message)],
        );
    }

    public static function unsupported(string $tool, int $phase): self
    {
        return new self(
            status: 'unsupported',
            durationMs: 0,
            failures: [],
            tool: $tool,
            phase: $phase,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        $failures = [];
        foreach ($payload['failures'] ?? [] as $row) {
            if (is_array($row)) {
                $failures[] = Failure::fromArray($row);
            }
        }

        return new self(
            status: (string) ($payload['status'] ?? 'error'),
            durationMs: (int) ($payload['duration_ms'] ?? 0),
            failures: $failures,
            value: $payload['value'] ?? null,
            hasValue: array_key_exists('value', $payload),
            tool: isset($payload['tool']) ? (string) $payload['tool'] : null,
            phase: isset($payload['phase']) ? (int) $payload['phase'] : null,
            rerun: array_key_exists('rerun', $payload) ? (bool) $payload['rerun'] : null,
        );
    }

    public function ok(): bool
    {
        return $this->status === 'passed';
    }

    /** @return array<string, mixed> */
    public function toArray(bool $detailed = false): array
    {
        $encoder = new JsonResultEncoder;

        return $detailed ? $encoder->detailed($this) : $encoder->compact($this);
    }

    public function toJson(bool $detailed = false): string
    {
        return (new JsonResultEncoder)->encode($this, $detailed);
    }
}
