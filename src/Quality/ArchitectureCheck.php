<?php

declare(strict_types=1);

namespace Probe\Quality;

use FilesystemIterator;
use Probe\Contract\QualityCheck;
use Probe\Result\Failure;
use Probe\Result\ProbeResult;
use Probe\Selective\AffectedRunner;
use Probe\Support\Paths;
use Probe\Support\Stats;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Architecture checks: configured forbid-use rules, plus any tests under tests/Architecture.
 * Pest arch() tests run through the same PHPUnit bridge when those files exist.
 */
final class ArchitectureCheck implements QualityCheck
{
    /**
     * @param  list<string>  $paths
     * @param  list<array<string, mixed>>  $rules
     */
    public function __construct(
        private readonly string $root,
        private readonly AffectedRunner $runner,
        private readonly array $paths = ['tests/Architecture', 'tests/Arch'],
        private readonly array $rules = [],
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
        $rules = isset($request['rules']) && is_array($request['rules']) ? $request['rules'] : $this->rules;
        $failures = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $prefix = isset($rule['path']) && is_string($rule['path']) ? $rule['path'] : '';
            $forbid = $rule['forbid_use'] ?? [];
            if ($prefix === '' || ! is_array($forbid)) {
                continue;
            }

            $directory = Paths::resolve($this->root, $prefix);
            if ($directory === null || ! is_dir($directory)) {
                continue;
            }

            foreach ($this->phpFiles($directory) as $file) {
                $text = (string) file_get_contents($file);
                foreach ($forbid as $symbol) {
                    if (! is_string($symbol) || $symbol === '') {
                        continue;
                    }

                    if (str_contains($text, 'use '.$symbol) || str_contains($text, $symbol.'::')) {
                        $relative = Paths::relative($this->root, $file);
                        $failures[] = new Failure(
                            id: (string) (count($failures) + 1),
                            message: $relative.' depends on '.$symbol,
                            type: 'architecture',
                            file: $relative,
                        );
                    }
                }
            }
        }

        $tests = $this->architectureTests();
        if ($failures === [] && $rules === [] && $tests === []) {
            return ProbeResult::error('architecture', 'no architecture rules or tests/Architecture files');
        }

        if ($failures !== []) {
            return new ProbeResult(
                status: 'failed',
                durationMs: Stats::elapsedMs($started),
                failures: $failures,
                value: ['rules' => count($rules)],
                hasValue: true,
            );
        }

        if ($tests === []) {
            return ProbeResult::passed(Stats::elapsedMs($started), ['rules' => count($rules), 'ran' => 0], true);
        }

        $ran = $this->runner->runFiles($tests, $started);
        $value = is_array($ran->value) ? $ran->value : [];
        $value['rules'] = count($rules);

        return new ProbeResult(
            status: $ran->status,
            durationMs: $ran->durationMs,
            failures: $ran->failures,
            value: $value,
            hasValue: true,
        );
    }

    /** @return list<string> */
    private function architectureTests(): array
    {
        $files = [];
        foreach ($this->paths as $relative) {
            $directory = (realpath($this->root) ?: $this->root).'/'.ltrim($relative, '/');
            if (! is_dir($directory)) {
                continue;
            }

            foreach ($this->phpFiles($directory) as $file) {
                if (str_ends_with($file, 'Test.php') || str_ends_with($file, '.pest.php')) {
                    $files[] = $file;
                }
            }
        }

        return $files;
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
