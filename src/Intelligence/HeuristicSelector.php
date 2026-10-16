<?php

declare(strict_types=1);

namespace Probe\Intelligence;

/**
 * Ranks tests from the dependency graph and boosts paths that failed recently.
 * This is the default selector. It does not call a model.
 */
final class HeuristicSelector implements Selector
{
    public function name(): string
    {
        return 'heuristic';
    }

    public function select(array $paths, array $context): array
    {
        $tests = [];
        foreach ($context['tests'] ?? [] as $test) {
            if (is_string($test) && $test !== '') {
                $tests[] = $test;
            }
        }

        $history = [];
        foreach ($context['history'] ?? [] as $path) {
            if (is_string($path) && $path !== '') {
                $history[$path] = true;
            }
        }

        $changed = [];
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '') {
                $changed[basename($path)] = true;
            }
        }

        $scored = [];
        foreach ($tests as $index => $test) {
            $score = 100 - $index;
            if (isset($history[$test])) {
                $score += 50;
            }

            $base = basename($test);
            foreach (array_keys($changed) as $name) {
                $stem = pathinfo($name, PATHINFO_FILENAME);
                if ($stem !== '' && str_contains($base, $stem)) {
                    $score += 25;
                    break;
                }
            }

            $scored[] = ['score' => $score, 'test' => $test];
        }

        usort($scored, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);
        $limit = isset($context['limit']) ? (int) $context['limit'] : 8;
        $limit = max(1, $limit);

        return array_slice(array_column($scored, 'test'), 0, $limit);
    }
}
