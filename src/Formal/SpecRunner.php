<?php

declare(strict_types=1);

namespace Probe\Formal;

use Probe\Contract\FormalBridge;
use Probe\Result\Failure;
use Probe\Result\ProbeResult;
use Probe\Support\Paths;
use Probe\Support\Process;
use Probe\Support\Stats;

/**
 * formal_check. Alloy, TLA+, Dafny, and Lean 4 are optional binaries.
 * A missing tool is an error result. A counterexample is a normal failure.
 */
final class SpecRunner implements FormalBridge
{
    /** @var array<string, string> */
    private const EXTENSIONS = [
        'als' => 'alloy',
        'tla' => 'tla',
        'dfy' => 'dafny',
        'lean' => 'lean',
    ];

    /** @var array<string, string> */
    private const DEFAULTS = [
        'alloy' => 'alloy',
        'tla' => 'tlc',
        'dafny' => 'dafny',
        'lean' => 'lean',
    ];

    /** @param  array<string, mixed>  $config */
    public function __construct(
        private readonly string $root,
        private readonly array $config = [],
    ) {}

    /** @param  array<string, mixed>  $request */
    public function check(array $request): array
    {
        return $this->run($request)->toArray();
    }

    /** @param  array<string, mixed>  $request */
    public function run(array $request): ProbeResult
    {
        $started = hrtime(true);
        $bridge = $this->bridgeName($request);
        if ($bridge === null) {
            return ProbeResult::error('bridge', 'formal_check needs a bridge or a .als, .tla, .dfy, or .lean file');
        }

        if (! isset(self::DEFAULTS[$bridge])) {
            return ProbeResult::error('bridge', 'unknown bridge '.$bridge);
        }

        $owned = null;
        $file = $this->file($request, $bridge, $owned);
        if ($file === null) {
            return ProbeResult::error('file', 'formal_check needs a spec file inside the project');
        }

        $binary = $this->binary($bridge);
        if ($binary === null) {
            $this->cleanup($owned);

            return ProbeResult::error('missing_tool', self::DEFAULTS[$bridge].' was not found');
        }

        $timeout = (int) ($this->config['formal']['timeout_ms'] ?? 20000);
        $command = array_merge(Process::phpCommand($binary), $this->arguments($bridge), [$file]);
        $result = Process::run($command, $this->root, null, $timeout);
        $this->cleanup($owned);

        if ($result['exit'] === 124) {
            return ProbeResult::error('timeout', $bridge.' timed out', $result['duration_ms']);
        }

        $verdict = Verdict::interpret($bridge, $result['exit'], $result['stdout'], $result['stderr']);
        $duration = max($result['duration_ms'], Stats::elapsedMs($started));

        if ($verdict['status'] === 'passed') {
            return ProbeResult::passed($duration, ['bridge' => $bridge], true);
        }

        if ($verdict['status'] === 'error') {
            return ProbeResult::error('missing_tool', $verdict['message'], $duration);
        }

        $relative = $owned !== null ? $bridge.'.spec' : Paths::relative($this->root, $file);

        return new ProbeResult(
            status: 'failed',
            durationMs: $duration,
            failures: [new Failure(
                id: '1',
                message: $verdict['message'],
                type: $bridge,
                file: $relative,
                trace: $verdict['trace'] !== '' ? $verdict['trace'] : null,
            )],
            value: ['bridge' => $bridge],
            hasValue: true,
        );
    }

    /** @param  array<string, mixed>  $request */
    private function bridgeName(array $request): ?string
    {
        $bridge = $request['bridge'] ?? null;
        if (is_string($bridge) && $bridge !== '') {
            return match (strtolower($bridge)) {
                'tla+', 'tla' => 'tla',
                'lean4', 'lean' => 'lean',
                default => strtolower($bridge),
            };
        }

        $file = $request['file'] ?? null;
        if (is_string($file) && $file !== '') {
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            return self::EXTENSIONS[$extension] ?? null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $request
     */
    private function file(array $request, string $bridge, ?string &$owned): ?string
    {
        $file = $request['file'] ?? null;
        if (is_string($file) && $file !== '') {
            return Paths::resolve($this->root, $file);
        }

        $code = $request['code'] ?? null;
        if (! is_string($code) || trim($code) === '') {
            return null;
        }

        $extension = array_search($bridge, self::EXTENSIONS, true);
        if (! is_string($extension)) {
            return null;
        }

        $path = tempnam(sys_get_temp_dir(), 'probe-spec-');
        if ($path === false) {
            return null;
        }

        $named = $path.'.'.$extension;
        rename($path, $named);
        file_put_contents($named, $code);
        $owned = $named;

        return $named;
    }

    private function binary(string $bridge): ?string
    {
        $configured = $this->config['formal'][$bridge] ?? self::DEFAULTS[$bridge];
        if (! is_string($configured) || $configured === '') {
            return null;
        }

        if (str_contains($configured, '/') || str_contains($configured, '\\')) {
            return is_file($configured) ? $configured : null;
        }

        return Process::which($configured);
    }

    /** @return list<string> */
    private function arguments(string $bridge): array
    {
        $configured = $this->config['formal']['args'][$bridge] ?? null;
        if (is_array($configured)) {
            return array_values(array_map('strval', $configured));
        }

        return match ($bridge) {
            'dafny' => ['verify'],
            default => [],
        };
    }

    private function cleanup(?string $owned): void
    {
        if ($owned !== null && is_file($owned)) {
            @unlink($owned);
        }
    }
}
