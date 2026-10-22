<?php

declare(strict_types=1);

namespace Probe\Quality;

use Probe\Result\Failure;
use Probe\Result\ProbeResult;
use Probe\Support\Paths;
use Probe\Support\Process;
use Probe\Support\Stats;

/**
 * type_check. vue-tsc, else tsc, with --noEmit over the project's tsconfig.
 * The compiler decides what it checks from that file, so paths only filter the
 * report; value.total keeps the whole count so a filter never hides a broken caller.
 */
final class TypeCheck
{
    /** @var list<string> */
    private const CANDIDATES = ['node_modules/.bin/vue-tsc', 'node_modules/.bin/tsc'];

    /** @param  array<string, mixed>  $config  the types block */
    public function __construct(
        private readonly string $root,
        private readonly array $config = [],
    ) {}

    /** @param  array<string, mixed>  $request */
    public function run(array $request): ProbeResult
    {
        $started = hrtime(true);

        $project = $this->project($request);
        if (! is_file($this->root.'/'.$project)) {
            return ProbeResult::error('tsconfig', $project.' was not found; type_check needs a TypeScript project file (types.project or PROBE_TSCONFIG)');
        }

        $binary = $this->binary();
        if ($binary === null) {
            return ProbeResult::error('missing_tool', 'vue-tsc or tsc was not found under node_modules/.bin; npm install --save-dev typescript vue-tsc, or set PROBE_TSC');
        }

        $name = basename($binary);
        $timeout = (int) ($this->config['timeout_ms'] ?? 120000);
        $result = Process::run([$binary, '--noEmit', '--pretty', 'false', '-p', $project], $this->root, null, $timeout);

        if ($result['exit'] === 124) {
            return ProbeResult::error('timeout', $name.' timed out after '.$timeout.' ms', $result['duration_ms']);
        }

        $diagnostics = self::parse($result['stdout']."\n".$result['stderr']);
        if ($diagnostics === [] && $result['exit'] !== 0) {
            $detail = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);

            return ProbeResult::error('tsc', $name.' exited '.$result['exit'].($detail === '' ? '' : ': '.self::tail($detail)), $result['duration_ms']);
        }

        $filter = $this->filter($request);
        $failures = [];
        foreach ($diagnostics as $diagnostic) {
            $file = $diagnostic['file'];
            if ($filter !== [] && ($file === null || ! self::within($file, $filter))) {
                continue;
            }

            $failures[] = new Failure(
                id: (string) (count($failures) + 1),
                message: $diagnostic['code'].': '.$diagnostic['text'].($file === null ? '' : ' at '.$file.($diagnostic['line'] === null ? '' : ':'.$diagnostic['line'])),
                type: 'types',
                file: $file,
                line: $diagnostic['line'],
            );
        }

        return new ProbeResult(
            status: $failures === [] ? 'passed' : 'failed',
            durationMs: Stats::elapsedMs($started),
            failures: $failures,
            value: ['errors' => count($failures), 'total' => count($diagnostics), 'binary' => $name, 'project' => $project],
            hasValue: true,
        );
    }

    /**
     * tsc's --pretty false lines: "path(line,col): error TSnnnn: text", a continuation
     * indented under it, or "error TSnnnn: text" when no file applies.
     *
     * @return list<array{file: ?string, line: ?int, code: string, text: string}>
     */
    public static function parse(string $output): array
    {
        $diagnostics = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^(.+?)\((\d+),\d+\): error (TS\d+): (.*)$/', $line, $match) === 1) {
                $diagnostics[] = ['file' => ltrim($match[1]), 'line' => (int) $match[2], 'code' => $match[3], 'text' => trim($match[4])];

                continue;
            }

            if (preg_match('/^error (TS\d+): (.*)$/', $line, $match) === 1) {
                $diagnostics[] = ['file' => null, 'line' => null, 'code' => $match[1], 'text' => trim($match[2])];

                continue;
            }

            if ($diagnostics !== [] && preg_match('/^\s+\S/', $line) === 1) {
                $last = count($diagnostics) - 1;
                $diagnostics[$last]['text'] .= ' '.trim($line);
            }
        }

        return $diagnostics;
    }

    private function binary(): ?string
    {
        $configured = $this->config['binary'] ?? null;
        if (is_string($configured) && $configured !== '') {
            if (str_contains($configured, '/')) {
                $path = str_starts_with($configured, '/') ? $configured : $this->root.'/'.$configured;

                return is_file($path) ? $path : null;
            }

            return Process::which($configured);
        }

        foreach (self::CANDIDATES as $candidate) {
            if (is_file($this->root.'/'.$candidate)) {
                return $this->root.'/'.$candidate;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $request */
    private function project(array $request): string
    {
        $project = $request['project'] ?? ($this->config['project'] ?? null);

        return is_string($project) && $project !== '' ? ltrim(Paths::relative($this->root, $project), '/') : 'tsconfig.json';
    }

    /**
     * @param  array<string, mixed>  $request
     * @return list<string>
     */
    private function filter(array $request): array
    {
        $paths = $request['paths'] ?? null;
        if (is_string($paths)) {
            $paths = explode(',', $paths);
        }

        if (! is_array($paths)) {
            return [];
        }

        $clean = [];
        foreach ($paths as $path) {
            if (is_string($path) && trim($path) !== '') {
                $clean[] = ltrim(Paths::relative($this->root, trim($path)), '/');
            }
        }

        return $clean;
    }

    /** @param  list<string>  $filter */
    private static function within(string $file, array $filter): bool
    {
        foreach ($filter as $prefix) {
            if ($file === $prefix || str_starts_with($file, rtrim($prefix, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    private static function tail(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return strlen($text) <= 300 ? $text : '...'.substr($text, -297);
    }
}
