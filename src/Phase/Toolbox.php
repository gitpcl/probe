<?php

declare(strict_types=1);

namespace Probe\Phase;

use Closure;
use Probe\Browser\HybridBrowser;
use Probe\Formal\SpecRunner;
use Probe\Intelligence\AutoProbe;
use Probe\Intelligence\FailureHistory;
use Probe\Intelligence\HeuristicSelector;
use Probe\Intelligence\LlmSelector;
use Probe\Quality\ArchitectureCheck;
use Probe\Quality\MutationCheck;
use Probe\Quality\PropertyCheck;
use Probe\Result\ProbeResult;
use Probe\Runner\DatabaseGuard;
use Probe\Runner\SnippetRunner;
use Probe\Selective\AffectedRunner;
use Probe\Selective\DependencyGraph;
use Probe\Selective\DurationBook;
use Probe\Selective\ShardPlanner;

/** Wires phases 2 to 5 onto the warm worker without changing the probe path. */
final class Toolbox
{
    private readonly AffectedRunner $affected;

    private readonly HybridBrowser $browser;

    private readonly MutationCheck $mutation;

    private readonly PropertyCheck $property;

    private readonly ArchitectureCheck $architecture;

    private readonly SpecRunner $formal;

    private readonly AutoProbe $auto;

    /** @param  array<string, mixed>  $config */
    public function __construct(
        private readonly string $root,
        array $config,
        SnippetRunner $snippets,
        string $home,
        ?DatabaseGuard $database = null,
        ?Closure $routes = null,
    ) {
        $graph = new DependencyGraph($root, (int) ($config['selective']['depth'] ?? 4), $routes);
        $configuredWorkers = $config['selective']['workers'] ?? null;
        $workers = is_numeric($configuredWorkers) && (int) $configuredWorkers > 0 ? (int) $configuredWorkers : 0;
        $runner = $config['selective']['runner'] ?? null;
        $this->affected = new AffectedRunner(
            $root,
            $graph,
            new ShardPlanner,
            new DurationBook($home.'/durations.json'),
            $workers,
            is_string($runner) && $runner !== '' ? $runner : null,
            (int) ($config['selective']['timeout_ms'] ?? 120000),
            (int) ($config['selective']['full_suite_under_ms'] ?? 15000),
            (string) ($config['selective']['mode'] ?? 'fork'),
            $database === null ? null : static fn () => $database->releaseConnections(),
        );
        $this->browser = new HybridBrowser($config, $root);
        $this->mutation = new MutationCheck(
            $root,
            $this->affected,
            limit: (int) ($config['mutate']['limit'] ?? 8),
        );
        $this->property = new PropertyCheck(
            $snippets,
            (int) ($config['property']['cases'] ?? 20),
            (int) ($config['property']['seed'] ?? 1),
        );
        $architecture = is_array($config['architecture'] ?? null) ? $config['architecture'] : [];
        $this->architecture = new ArchitectureCheck(
            $root,
            $this->affected,
            is_array($architecture['paths'] ?? null) ? $architecture['paths'] : ['tests/Architecture', 'tests/Arch'],
            is_array($architecture['rules'] ?? null) ? $architecture['rules'] : [],
        );
        $this->formal = new SpecRunner($root, is_array($config['formal'] ?? null) ? $config['formal'] : []);
        $intelligence = is_array($config['intelligence'] ?? null) ? $config['intelligence'] : [];
        $this->auto = new AutoProbe(
            $root,
            $graph,
            $this->affected,
            $snippets,
            new HeuristicSelector,
            new LlmSelector(
                (bool) ($intelligence['llm'] ?? false),
                isset($intelligence['endpoint']) && is_string($intelligence['endpoint']) ? $intelligence['endpoint'] : null,
                isset($intelligence['api_key']) && is_string($intelligence['api_key']) ? $intelligence['api_key'] : null,
                (string) ($intelligence['model'] ?? ''),
                (int) ($intelligence['timeout_ms'] ?? 2000),
            ),
            new FailureHistory($home.'/failure-history.json'),
            (int) ($intelligence['limit'] ?? 8),
        );
    }

    /** @param  array<string, mixed>  $request */
    public function handle(string $tool, array $request): ?ProbeResult
    {
        return match ($tool) {
            'probe_browser' => $this->browser->check($request),
            'run_affected' => $this->affected->run($request),
            'mutate_check' => $this->mutation->run($request),
            'property_check' => $this->property->run($request),
            'architecture_check' => $this->architecture->run($request),
            'formal_check' => $this->formal->run($request),
            'auto_probe' => $this->auto->run($request),
            default => null,
        };
    }
}
