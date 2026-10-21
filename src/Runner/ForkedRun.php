<?php

declare(strict_types=1);

namespace Probe\Runner;

use Probe\Result\Failure;
use Probe\Result\ProbeResult;
use Probe\Support\Stats;
use Probe\Worker\Protocol;
use Throwable;

/**
 * Runs one probe in a forked child so nothing it changes in the process survives:
 * config, faked facades, Carbon's test time, auth guards, static caches, a fatal
 * error or an exit(). The child sends its result back over a socket pair and is
 * then killed outright, so no shutdown function or destructor inherited from the
 * worker runs in it. The parent waits the probe timeout plus a grace, then kills.
 */
final class ForkedRun
{
    private const GRACE_MS = 500;

    public function __construct(private readonly bool $enabled = true) {}

    public function available(): bool
    {
        return $this->enabled
            && function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('posix_kill');
    }

    /**
     * @param  callable(): ProbeResult  $body
     */
    public function run(callable $body, int $timeoutMs): ProbeResult
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            return $body();
        }

        [$parentEnd, $childEnd] = $pair;
        $pid = pcntl_fork();
        if ($pid === -1) {
            fclose($parentEnd);
            fclose($childEnd);

            return $body();
        }

        if ($pid === 0) {
            fclose($parentEnd);
            $this->child($body, $childEnd);
            exit(0);
        }

        fclose($childEnd);
        $started = hrtime(true);

        try {
            return $this->collect($parentEnd, $pid, $timeoutMs, $started);
        } finally {
            fclose($parentEnd);
            pcntl_waitpid($pid, $status);
        }
    }

    /**
     * @param  callable(): ProbeResult  $body
     * @param  resource  $socket
     */
    private function child(callable $body, $socket): void
    {
        if (function_exists('pcntl_signal')) {
            // The worker's handlers would remove its socket file from this process.
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);
        }

        try {
            $payload = $body()->toArray(true);
        } catch (Throwable $exception) {
            $payload = ProbeResult::failed(0, Failure::fromThrowable($exception))->toArray(true);
        }

        try {
            Protocol::write($socket, $payload);
        } catch (Throwable) {
            // The parent stopped waiting; there is nobody to report to.
        }

        posix_kill((int) getmypid(), SIGKILL);
    }

    /** @param  resource  $socket */
    private function collect($socket, int $pid, int $timeoutMs, int $started): ProbeResult
    {
        $waitMs = $timeoutMs + self::GRACE_MS;
        $read = [$socket];
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, intdiv($waitMs, 1000), ($waitMs % 1000) * 1000);

        if ($ready === false || $ready === 0) {
            posix_kill($pid, SIGKILL);

            return ProbeResult::failed(
                Stats::elapsedMs($started),
                Failure::fromThrowable(new ProbeTimeoutException('probe timed out')),
            );
        }

        try {
            stream_set_timeout($socket, max(1, (int) ceil($waitMs / 1000)));
            $line = Protocol::readLine($socket);
        } catch (Throwable) {
            posix_kill($pid, SIGKILL);

            return ProbeResult::failed(Stats::elapsedMs($started), new Failure(
                id: 'exit',
                message: 'probe process ended before returning a result (exit or fatal error); see the worker log',
            ));
        }

        $decoded = json_decode($line, true);
        if (! is_array($decoded)) {
            return ProbeResult::error('fork', 'probe process returned invalid JSON');
        }

        return ProbeResult::fromPayload($decoded);
    }
}
