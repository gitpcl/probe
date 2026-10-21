<?php

declare(strict_types=1);

namespace Probe\Browser;

use Probe\Support\Stats;

/**
 * A browser process kept alive next to the worker. Started on the first request,
 * one JSON line in and one out per probe, killed and restarted after a timeout or
 * a broken pipe. Its stdin is the leash: when the worker dies the pipe closes and
 * the browser exits.
 */
final class PlaywrightSidecar
{
    private const GRACE_MS = 500;

    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private ?string $errorFile = null;

    /**
     * @param  list<string>  $command
     */
    public function __construct(
        private readonly array $command,
        private readonly string $cwd,
    ) {}

    /**
     * The same shape Process::run returns, so the caller reads a sidecar answer and
     * a one-shot answer alike: exit 0 with the JSON line, 124 on timeout, 127 when
     * the process could not start or died before answering.
     *
     * @return array{exit: int, stdout: string, stderr: string, duration_ms: int}
     */
    public function request(string $payload, int $timeoutMs): array
    {
        $started = hrtime(true);
        if (! $this->start()) {
            return ['exit' => 127, 'stdout' => '', 'stderr' => $this->errors(), 'duration_ms' => Stats::elapsedMs($started)];
        }

        if (@fwrite($this->pipes[0], $payload."\n") === false) {
            $this->stop();

            return ['exit' => 127, 'stdout' => '', 'stderr' => 'browser process is gone', 'duration_ms' => Stats::elapsedMs($started)];
        }

        $line = $this->readLine($timeoutMs + self::GRACE_MS);
        if ($line === null) {
            $this->stop();

            return ['exit' => 124, 'stdout' => '', 'stderr' => 'browser process did not answer in time', 'duration_ms' => Stats::elapsedMs($started)];
        }

        if ($line === '') {
            $stderr = $this->errors();
            $this->stop();

            return ['exit' => 127, 'stdout' => '', 'stderr' => $stderr !== '' ? $stderr : 'browser process ended before answering', 'duration_ms' => Stats::elapsedMs($started)];
        }

        return ['exit' => 0, 'stdout' => $line, 'stderr' => '', 'duration_ms' => Stats::elapsedMs($started)];
    }

    public function stop(): void
    {
        if ($this->process === null) {
            return;
        }

        foreach ($this->pipes as $pipe) {
            @fclose($pipe);
        }

        if (proc_get_status($this->process)['running']) {
            @proc_terminate($this->process, 9);
        }

        @proc_close($this->process);
        $this->process = null;
        $this->pipes = [];
        if ($this->errorFile !== null) {
            @unlink($this->errorFile);
            $this->errorFile = null;
        }
    }

    private function start(): bool
    {
        if ($this->process !== null) {
            if (proc_get_status($this->process)['running']) {
                return true;
            }

            $this->stop();
        }

        $errorFile = tempnam(sys_get_temp_dir(), 'probe-browser-');
        if ($errorFile === false) {
            return false;
        }

        $process = proc_open($this->command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', $errorFile, 'w'],
        ], $pipes, $this->cwd);

        if (! is_resource($process)) {
            @unlink($errorFile);

            return false;
        }

        stream_set_blocking($pipes[1], false);
        $this->process = $process;
        $this->pipes = $pipes;
        $this->errorFile = $errorFile;

        return true;
    }

    /** One line, null on timeout, empty string when the process closed its output first. */
    private function readLine(int $timeoutMs): ?string
    {
        $buffer = '';
        $deadline = microtime(true) + ($timeoutMs / 1000);

        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return null;
            }

            $read = [$this->pipes[1]];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, (int) floor($remaining), (int) (($remaining - floor($remaining)) * 1_000_000));
            if ($ready === false) {
                return '';
            }

            if ($ready === 0) {
                continue;
            }

            $chunk = fread($this->pipes[1], 65536);
            if ($chunk === false || ($chunk === '' && feof($this->pipes[1]))) {
                return '';
            }

            $buffer .= $chunk;
            if (($position = strpos($buffer, "\n")) !== false) {
                return substr($buffer, 0, $position);
            }
        }
    }

    private function errors(): string
    {
        return $this->errorFile === null ? '' : trim((string) @file_get_contents($this->errorFile));
    }
}
