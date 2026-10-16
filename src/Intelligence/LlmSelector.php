<?php

declare(strict_types=1);

namespace Probe\Intelligence;

use RuntimeException;
use Throwable;

/**
 * Optional model selector. It stays off unless intelligence.llm is true and an endpoint is set.
 * Tests and CI keep using the heuristic selector.
 */
final class LlmSelector implements Selector
{
    public function __construct(
        private readonly bool $enabled = false,
        private readonly ?string $endpoint = null,
        private readonly ?string $apiKey = null,
        private readonly string $model = '',
        private readonly int $timeoutMs = 2000,
    ) {}

    public function name(): string
    {
        return 'llm';
    }

    public function enabled(): bool
    {
        return $this->enabled && is_string($this->endpoint) && $this->endpoint !== '';
    }

    public function select(array $paths, array $context): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $candidates = [];
        foreach ($context['tests'] ?? [] as $test) {
            if (is_string($test) && $test !== '') {
                $candidates[] = $test;
            }
        }

        try {
            $body = $this->post([
                'model' => $this->model,
                'paths' => array_values(array_filter($paths, 'is_string')),
                'tests' => $candidates,
            ]);

            return $this->parse($body);
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<string> */
    public function parse(string $body): array
    {
        $data = json_decode($body, true);
        if (! is_array($data)) {
            return [];
        }

        $tests = $data['tests'] ?? $data['selected'] ?? null;
        if (! is_array($tests)) {
            return [];
        }

        $clean = [];
        foreach ($tests as $test) {
            if (is_string($test) && $test !== '') {
                $clean[] = $test;
            }
        }

        return $clean;
    }

    /** @param  array<string, mixed>  $payload */
    private function post(array $payload): string
    {
        $headers = "Content-Type: application/json\r\nAccept: application/json\r\n";
        if (is_string($this->apiKey) && $this->apiKey !== '') {
            $headers .= 'Authorization: Bearer '.$this->apiKey."\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headers,
                'content' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'timeout' => max(1, (int) ceil($this->timeoutMs / 1000)),
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents((string) $this->endpoint, false, $context);
        if ($body === false) {
            throw new RuntimeException('llm request failed');
        }

        return $body;
    }
}
