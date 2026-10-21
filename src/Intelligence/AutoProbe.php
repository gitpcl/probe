<?php

declare(strict_types=1);

namespace Probe\Intelligence;

use Probe\Result\ProbeResult;
use Probe\Runner\SnippetRunner;
use Probe\Selective\AffectedRunner;
use Probe\Selective\DependencyGraph;
use Probe\Selective\GitChanges;
use Probe\Support\Paths;

/**
 * auto_probe picks a small set of tests, or a class-exists smoke probe when nothing matches.
 * The LLM selector is used only when it is configured and returns a list. Otherwise the heuristic runs.
 */
final class AutoProbe
{
    public function __construct(
        private readonly string $root,
        private readonly DependencyGraph $graph,
        private readonly AffectedRunner $runner,
        private readonly SnippetRunner $snippets,
        private readonly Selector $heuristic,
        private readonly Selector $llm,
        private readonly FailureHistory $history,
        private readonly int $limit = 8,
    ) {}

    /** @param  array<string, mixed>  $request */
    public function run(array $request): ProbeResult
    {
        $paths = $this->paths($request);
        if ($paths === []) {
            return ProbeResult::error('paths', 'auto_probe needs paths or a git diff');
        }

        $limit = isset($request['limit']) ? max(1, (int) $request['limit']) : $this->limit;
        $tests = $this->runner->select($paths)['files'];
        $context = [
            'tests' => $tests,
            'history' => $this->history->recent(),
            'limit' => $limit,
        ];

        $chosen = $this->llm->select($paths, $context);
        $selector = 'llm';
        if ($chosen === []) {
            $chosen = $this->heuristic->select($paths, $context);
            $selector = $this->heuristic->name();
        }

        $chosen = array_values(array_filter($chosen, 'is_string'));
        if ($chosen !== []) {
            $result = $this->runner->run(['tests' => $chosen, 'paths' => $paths]);
            if ($result->status === 'failed') {
                $this->history->push($chosen);
            }

            $value = is_array($result->value) ? $result->value : [];
            $value['selector'] = $selector;

            return new ProbeResult(
                status: $result->status,
                durationMs: $result->durationMs,
                failures: $result->failures,
                value: $value,
                hasValue: true,
            );
        }

        $classes = [];
        foreach ($paths as $path) {
            $resolved = Paths::resolve($this->root, $path);
            if ($resolved === null) {
                continue;
            }

            foreach ($this->graph->classesIn($resolved) as $class) {
                $classes[] = $class;
            }
        }

        $classes = array_values(array_unique($classes));
        if ($classes === []) {
            return ProbeResult::passed(0, ['selector' => $selector, 'ran' => 0], true);
        }

        $result = $this->snippets->run($this->smokeSnippet($classes));
        if ($result->status !== 'passed') {
            return $result;
        }

        $value = is_array($result->value) ? $result->value : [];
        $value['selector'] = $selector;
        $value['ran'] = 0;

        return new ProbeResult(
            status: 'passed',
            durationMs: $result->durationMs,
            failures: [],
            value: $value,
            hasValue: true,
        );
    }

    /** @param  list<string>  $classes */
    public function smokeSnippet(array $classes): string
    {
        $exported = var_export($classes, true);

        return <<<PHP
\$missing = [];
foreach ({$exported} as \$class) {
    if (!class_exists(\$class) && !interface_exists(\$class) && !trait_exists(\$class) && !enum_exists(\$class)) {
        \$missing[] = \$class;
    }
}
if (\$missing !== []) {
    throw new RuntimeException('missing '.implode(', ', \$missing));
}
return ['classes' => count({$exported})];
PHP;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return list<string>
     */
    private function paths(array $request): array
    {
        $paths = $request['paths'] ?? null;
        if (is_string($paths) && $paths !== '') {
            $paths = array_map('trim', explode(',', $paths));
        }

        if (! is_array($paths) || $paths === []) {
            return GitChanges::names($this->root);
        }

        $clean = [];
        foreach ($paths as $path) {
            if (is_string($path) && trim($path) !== '') {
                $clean[] = trim($path);
            }
        }

        return $clean;
    }
}
