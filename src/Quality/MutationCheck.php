<?php

declare(strict_types=1);

namespace Probe\Quality;

use Probe\Contract\QualityCheck;
use Probe\Result\Failure;
use Probe\Result\ProbeResult;
use Probe\Selective\AffectedRunner;
use Probe\Selective\DependencyGraph;
use Probe\Support\Paths;
use Probe\Support\Stats;

/** Scoped mutation: flip operators in one file and see whether the affected tests notice. */
final class MutationCheck implements QualityCheck
{
    public function __construct(
        private readonly string $root,
        private readonly AffectedRunner $runner,
        private readonly DependencyGraph $graph,
        private readonly Mutator $mutator = new Mutator,
        private readonly int $limit = 8,
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
        $given = $request['path'] ?? $request['file'] ?? null;
        if (! is_string($given) || trim($given) === '') {
            return ProbeResult::error('path', 'mutate_check needs a path');
        }

        $path = Paths::resolve($this->root, $given);
        if ($path === null || ! is_file($path)) {
            return ProbeResult::error('path', 'mutate_check path is outside the project or missing');
        }

        if (str_contains(Paths::relative($this->root, $path), 'vendor/')) {
            return ProbeResult::error('path', 'mutate_check does not mutate vendor files');
        }

        $limit = isset($request['limit']) ? (int) $request['limit'] : $this->limit;
        $original = (string) file_get_contents($path);
        $mutants = $this->mutator->mutants($original, max(1, $limit));
        if ($mutants === []) {
            return ProbeResult::error('mutate', 'no operators matched in that file');
        }

        $tests = isset($request['tests']) && is_array($request['tests'])
            ? array_values(array_filter($request['tests'], 'is_string'))
            : $this->graph->affectedTests([$path]);

        if ($tests === []) {
            return ProbeResult::error('tests', 'no tests cover that file');
        }

        $baseline = $this->runner->runFiles($tests);
        if ($this->isMissingTool($baseline)) {
            return $baseline;
        }

        if ($baseline->status !== 'passed') {
            return ProbeResult::error('tests', 'target tests already fail, so mutation results would be noise', $baseline->durationMs);
        }

        $survived = [];
        $killed = 0;
        $restore = static function () use ($path, $original): void {
            file_put_contents($path, $original);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($path, true);
            }
        };
        register_shutdown_function($restore);

        try {
            foreach ($mutants as $mutant) {
                file_put_contents($path, $mutant['code']);
                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate($path, true);
                }

                $result = $this->runner->runFiles($tests);
                if ($result->status === 'error' && $this->isMissingTool($result)) {
                    return $result;
                }

                if ($result->status === 'passed') {
                    $survived[] = $mutant;
                } else {
                    $killed++;
                }
            }
        } finally {
            $restore();
        }

        $duration = Stats::elapsedMs($started);
        if ($survived === []) {
            return ProbeResult::passed($duration, [
                'killed' => $killed,
                'survived' => 0,
            ], true);
        }

        $failures = [];
        foreach ($survived as $mutant) {
            $relative = Paths::relative($this->root, $path);
            $failures[] = new Failure(
                id: (string) (count($failures) + 1),
                message: 'survived '.$mutant['from'].' to '.$mutant['to'].' at '.$relative.':'.$mutant['line'],
                type: 'mutation',
                file: $relative,
                line: $mutant['line'],
            );
        }

        return new ProbeResult(
            status: 'failed',
            durationMs: $duration,
            failures: $failures,
            value: ['killed' => $killed, 'survived' => count($survived)],
            hasValue: true,
        );
    }

    private function isMissingTool(ProbeResult $result): bool
    {
        foreach ($result->failures as $failure) {
            if ($failure->id === 'missing_tool') {
                return true;
            }
        }

        return false;
    }
}
