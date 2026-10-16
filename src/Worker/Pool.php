<?php

declare(strict_types=1);

namespace Probe\Worker;

use Probe\Project;
use Probe\Result\ProbeResult;
use RuntimeException;
use Throwable;

/**
 * Keeps a small set of warm PHP workers alive.
 * Agent tools enter through worker 0. run_affected shards PHPUnit processes
 * using the same worker count when there is more than one test file.
 */
final class Pool
{
    private const CLIENT_GRACE_MS = 1500;

    public function __construct(
        private readonly Client $client = new Client(),
        private readonly Registry $registry = new Registry(),
    ) {}

    public function runCode(string $root, string $code, ?int $timeoutMs = null): ProbeResult
    {
        return $this->runTool($root, 'probe', [
            'code' => $code,
            'timeout_ms' => $timeoutMs,
        ], $timeoutMs);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function runTool(string $root, string $tool, array $extra = [], ?int $timeoutMs = null): ProbeResult
    {
        $request = array_merge(['tool' => $tool], $extra);
        $line = $this->dispatch($root, $request, $timeoutMs);
        $decoded = json_decode($line, true);
        if (! is_array($decoded)) {
            return ProbeResult::error('worker', 'worker returned invalid JSON');
        }

        return ProbeResult::fromPayload($decoded);
    }

    /**
     * @param  array<string, mixed>  $request
     */
    public function dispatch(string $root, array $request, ?int $timeoutMs = null): string
    {
        $timeoutMs = $this->resolveTimeout($root, $request, $timeoutMs);
        $request['timeout_ms'] ??= $timeoutMs;
        $socket = $this->ensure($root);

        try {
            return $this->client->requestLine($socket, $request, ($timeoutMs + self::CLIENT_GRACE_MS) / 1000);
        } catch (WorkerTimeout) {
            $this->stop($root);

            return json_encode([
                'status' => 'error',
                'duration_ms' => $timeoutMs,
                'failures' => [['id' => 'timeout', 'message' => 'probe timed out']],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
    }

    public function ensure(string $root, bool $cold = false): string
    {
        $home = Project::home($root);
        $this->makeDirectory($home);
        $lock = $this->lock($home.'/supervisor.lock');

        try {
            if ($cold) {
                $this->stopUnlocked($root);
            }

            $primary = null;
            $count = Project::workerCount($root);
            for ($index = 0; $index < $count; $index++) {
                $socket = $this->ensureOne($root, $index);
                if ($index === 0) {
                    $primary = $socket;
                }
            }

            return $primary ?? Project::socket($root, 0);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function stop(string $root): void
    {
        $home = Project::home($root);
        if (! is_dir($home) && ! is_file(Project::activeFile($root))) {
            return;
        }

        $this->makeDirectory($home);
        $lock = $this->lock($home.'/supervisor.lock');

        try {
            $this->stopUnlocked($root);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{status: string, workers: list<array<string, mixed>>} */
    public function status(string $root): array
    {
        $active = $this->registry->read(Project::activeFile($root));
        $running = [];

        foreach ($active['workers'] ?? [] as $worker) {
            if (! is_array($worker)) {
                continue;
            }

            $pid = (int) ($worker['pid'] ?? 0);
            if ($this->pidAlive($pid)) {
                $running[] = $worker;
            }
        }

        return [
            'status' => $running === [] ? 'stopped' : 'running',
            'workers' => $running,
        ];
    }

    private function ensureOne(string $root, int $index): string
    {
        $socket = Project::socket($root, $index);
        $worker = $this->worker($root, $index);

        if ($worker !== null && $this->healthy($worker, $socket)) {
            return $socket;
        }

        $this->clearStale($socket, (int) ($worker['pid'] ?? 0));
        $spawnedPid = $this->spawn($root, $socket, $index);

        try {
            $this->waitUntilReady($root, $socket, $index);
        } catch (Throwable $exception) {
            if ($spawnedPid > 0) {
                $this->signal($spawnedPid, 9);
            }

            throw $exception;
        }

        return $socket;
    }

    /** @return array<string, mixed>|null */
    private function worker(string $root, int $index): ?array
    {
        $active = $this->registry->read(Project::activeFile($root));
        $worker = $active['workers'][(string) $index] ?? null;

        return is_array($worker) ? $worker : null;
    }

    /** @param  array<string, mixed>  $worker */
    private function healthy(array $worker, string $socket): bool
    {
        if (($worker['socket'] ?? null) !== $socket) {
            return false;
        }

        // A Unix socket is not a regular file, so is_file() is always false.
        if (! file_exists($socket)) {
            return false;
        }

        return $this->pidAlive((int) ($worker['pid'] ?? 0));
    }

    private function waitUntilReady(string $root, string $socket, int $index): void
    {
        $deadline = microtime(true) + Project::bootTimeoutSeconds($root);

        do {
            $worker = $this->worker($root, $index);
            if (
                $worker !== null
                && $this->pidAlive((int) ($worker['pid'] ?? 0))
                && $this->ping($socket)
            ) {
                return;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        $log = Project::log($root);
        $tail = $this->tail($log);

        throw new RuntimeException(
            'Probe worker '.$index.' did not become ready. See '.$log.($tail !== '' ? "\n".$tail : '')
        );
    }

    private function ping(string $socket): bool
    {
        try {
            $line = $this->client->requestLine($socket, ['tool' => 'ping'], 2.0);
            $data = json_decode($line, true);

            return is_array($data) && ($data['status'] ?? null) === 'passed';
        } catch (Throwable) {
            return false;
        }
    }

    private function spawn(string $root, string $socket, int $index): int
    {
        $artisan = $root.'/artisan';
        if (! is_file($artisan)) {
            throw new RuntimeException('Probe could not find artisan in '.$root.'.');
        }

        $home = Project::home($root);
        $this->makeDirectory($home);
        $log = Project::log($root);
        $this->makeDirectory(dirname($log));
        file_put_contents($log, "\n[".date('c')."] starting worker {$index}\n", FILE_APPEND);

        $args = [Project::phpBinary($root)];
        if (Project::opcacheEnabled($root)) {
            $cache = $home.'/opcache';
            $this->makeDirectory($cache);
            $args[] = '-d';
            $args[] = 'opcache.enable=1';
            $args[] = '-d';
            $args[] = 'opcache.enable_cli=1';
            $args[] = '-d';
            $args[] = 'opcache.file_cache='.$cache;
        }

        $args[] = $artisan;
        $args[] = 'probe:work';
        $args[] = '--socket='.$socket;
        $args[] = '--index='.(string) $index;
        $args[] = '--home='.$home;

        $command = 'nohup '.implode(' ', array_map('escapeshellarg', $args))
            .' >> '.escapeshellarg($log).' 2>&1 & echo $!';

        $output = $this->shell($command);
        $pid = (int) trim($output);
        if ($pid <= 0) {
            throw new RuntimeException('Failed to start the Probe worker. See '.$log."\n".$this->tail($log));
        }

        return $pid;
    }

    private function stopUnlocked(string $root): void
    {
        $active = $this->registry->read(Project::activeFile($root));
        $workers = is_array($active['workers'] ?? null) ? $active['workers'] : [];

        foreach ($workers as $worker) {
            if (! is_array($worker)) {
                continue;
            }

            $socket = $worker['socket'] ?? null;
            if (is_string($socket) && $socket !== '' && file_exists($socket)) {
                try {
                    $this->client->requestLine($socket, ['tool' => 'shutdown'], 1.0);
                } catch (Throwable) {
                    // The signal below covers a worker that did not answer.
                }
            }
        }

        usleep(100_000);
        $this->signalWorkers($root, 15);
        usleep(100_000);
        $this->signalWorkers($root, 9);

        $active = $this->registry->read(Project::activeFile($root));
        foreach ($active['workers'] ?? [] as $worker) {
            if (! is_array($worker)) {
                continue;
            }

            $socket = $worker['socket'] ?? null;
            if (is_string($socket) && file_exists($socket)) {
                @unlink($socket);
            }
        }
    }

    private function signalWorkers(string $root, int $signal): void
    {
        $active = $this->registry->read(Project::activeFile($root));
        foreach ($active['workers'] ?? [] as $worker) {
            if (! is_array($worker)) {
                continue;
            }

            $pid = (int) ($worker['pid'] ?? 0);
            if ($this->pidAlive($pid)) {
                $this->signal($pid, $signal);
            }
        }
    }

    private function clearStale(string $socket, int $pid): void
    {
        if ($pid > 0 && $this->pidAlive($pid)) {
            $this->signal($pid, 15);
            usleep(100_000);
            if ($this->pidAlive($pid)) {
                $this->signal($pid, 9);
                usleep(50_000);
            }
        }

        if (file_exists($socket)) {
            @unlink($socket);
        }
    }

    private function pidAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            if (posix_kill($pid, 0)) {
                return true;
            }

            return function_exists('posix_get_last_error') && posix_get_last_error() === 1;
        }

        return is_dir('/proc/'.$pid);
    }

    private function signal(int $pid, int $signal): void
    {
        if ($pid <= 0) {
            return;
        }

        if (function_exists('posix_kill')) {
            @posix_kill($pid, $signal);

            return;
        }

        exec('kill -'.(int) $signal.' '.(int) $pid.' >/dev/null 2>&1');
    }

    /**
     * @param  array<string, mixed>  $request
     */
    private function resolveTimeout(string $root, array $request, ?int $timeoutMs): int
    {
        if ($timeoutMs !== null && $timeoutMs > 0) {
            return $timeoutMs;
        }

        if (isset($request['timeout_ms']) && is_numeric($request['timeout_ms']) && (int) $request['timeout_ms'] > 0) {
            return (int) $request['timeout_ms'];
        }

        return Project::timeoutMs($root);
    }

    /** @return resource */
    private function lock(string $path)
    {
        $this->makeDirectory(dirname($path));
        $lock = fopen($path, 'c');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('Could not lock the Probe supervisor.');
        }

        return $lock;
    }

    private function makeDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (! mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException('Could not create '.$path);
        }
    }

    private function shell(string $command): string
    {
        if (function_exists('shell_exec')) {
            $output = shell_exec($command);

            return is_string($output) ? $output : '';
        }

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            return '';
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return is_string($output) ? $output : '';
    }

    private function tail(string $log): string
    {
        if (! is_file($log)) {
            return '';
        }

        $data = file_get_contents($log);
        if ($data === false || $data === '') {
            return '';
        }

        return substr($data, -1500);
    }
}
