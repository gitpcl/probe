<?php

declare(strict_types=1);

namespace Probe\Console;

use Illuminate\Console\Command;

final class ProbeCommand extends Command
{
    protected $signature = 'probe
        {code? : PHP snippet. Return a value to include it in the JSON.}
        {--file= : Read the snippet from a file}
        {--tool=probe : probe, get_failures, rerun_failed, or run_affected}
        {--timeout= : Timeout in milliseconds}
        {--id= : Failure id for get_failures}
        {--paths= : Comma-separated paths for run_affected}
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
        );

        $this->output->setDecorated(false);

        return $dispatcher->run($invocation, function (string $json): void {
            $this->output->write($json);
        });
    }
}
