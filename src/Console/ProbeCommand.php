<?php

declare(strict_types=1);

namespace Probe\Console;

use Illuminate\Console\Command;

final class ProbeCommand extends Command
{
    protected $signature = 'probe
        {code? : PHP snippet. Return a value to include it in the JSON.}
        {--file= : Read the snippet from a file}
        {--tool=probe : Agent tool name}
        {--timeout= : Timeout in milliseconds}
        {--id= : Failure id for get_failures}
        {--paths= : Comma-separated paths for run_affected and auto_probe}
        {--url= : Page URL for probe_browser}
        {--contains= : Text the page must include}
        {--absent= : Text the page must not include}
        {--engine= : lightpanda, playwright, or auto}
        {--steps= : JSON array of browser steps}
        {--http-status= : Expected HTTP status when the engine reports one}
        {--bridge= : alloy, tla, dafny, or lean}
        {--cases= : Property cases to generate}
        {--seed= : Property generator seed}
        {--limit= : Mutation or auto_probe limit}
        {--property= : Built-in property name}
        {--cold : Stop any warm worker before running}
        {--benchmark : Measure cold and warm probe latency}
        {--samples=20 : Samples used by --benchmark}
        {--stop : Stop warm workers}
        {--status : Print worker status JSON}';

    protected $description = 'Run a Probe tool and print one JSON object';

    public function handle(Dispatcher $dispatcher): int
    {
        $timeout = $this->option('timeout');
        $invocation = new Invocation(
            tool: (string) ($this->option('tool') ?: 'probe'),
            code: is_string($this->argument('code')) ? $this->argument('code') : null,
            file: is_string($this->option('file')) && $this->option('file') !== '' ? $this->option('file') : null,
            timeoutMs: is_numeric($timeout) ? (int) $timeout : null,
            id: is_string($this->option('id')) && $this->option('id') !== '' ? $this->option('id') : null,
            paths: is_string($this->option('paths')) && $this->option('paths') !== '' ? $this->option('paths') : null,
            cold: (bool) $this->option('cold'),
            benchmark: (bool) $this->option('benchmark'),
            stop: (bool) $this->option('stop'),
            status: (bool) $this->option('status'),
            samples: max(1, (int) $this->option('samples')),
            url: $this->stringOption('url'),
            bridge: $this->stringOption('bridge'),
            cases: $this->intOption('cases'),
            seed: $this->intOption('seed'),
            engine: $this->stringOption('engine'),
            contains: $this->stringOption('contains'),
            absent: $this->stringOption('absent'),
            limit: $this->intOption('limit'),
            property: $this->stringOption('property'),
            steps: $this->stringOption('steps'),
            httpStatus: $this->intOption('http-status'),
        );

        $this->output->setDecorated(false);

        return $dispatcher->run($invocation, function (string $json): void {
            $this->output->write($json);
        });
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        return is_numeric($value) ? (int) $value : null;
    }
}
