<?php

declare(strict_types=1);

namespace Probe\Runner;

use ErrorException;
use Probe\Result\Failure;
use Probe\Result\ProbeResult;
use Probe\Support\Stats;
use Throwable;

final class SnippetRunner
{
    /** @var callable|int|null The SIGALRM handler that was active before the current probe armed its timeout. */
    private mixed $previousAlarmHandler = null;

    public function __construct(
        private readonly DatabaseGuard $database,
        private readonly int $maxValueBytes = 1024,
        private readonly int $defaultTimeoutMs = 5000,
        private readonly ?ForkedRun $fork = null,
    ) {}

    public function run(string $code, ?int $timeoutMs = null): ProbeResult
    {
        $timeoutMs ??= $this->defaultTimeoutMs;

        if ($this->fork === null || ! $this->fork->available()) {
            return $this->execute($code, $timeoutMs);
        }

        $this->database->releaseConnections();

        return $this->fork->run(fn (): ProbeResult => $this->execute($code, $timeoutMs), $timeoutMs);
    }

    /** fork: each probe runs in a child and leaves nothing behind. process: it runs in the worker. */
    public function isolation(): string
    {
        return $this->fork !== null && $this->fork->available() ? 'fork' : 'process';
    }

    private function execute(string $code, int $timeoutMs): ProbeResult
    {
        $started = hrtime(true);
        $level = ob_get_level();
        ob_start();
        $this->armTimeout($timeoutMs);
        $this->pushErrorHandler();

        try {
            $normalized = $this->database->isolate(function () use ($code): array {
                return $this->normalize(probe_execute_snippet($code));
            });

            return ProbeResult::passed(Stats::elapsedMs($started), $normalized[0], $normalized[1]);
        } catch (Throwable $exception) {
            return ProbeResult::failed(
                Stats::elapsedMs($started),
                $this->database->annotate(Failure::fromThrowable($exception)),
            );
        } finally {
            $this->disarmTimeout();
            restore_error_handler();
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    /**
     * @return array{0: mixed, 1: bool}
     */
    private function normalize(mixed $value): array
    {
        if ($value === null) {
            return [null, false];
        }

        if (is_bool($value) || is_int($value)) {
            return [$value, true];
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                return [['_type' => 'float'], true];
            }

            return [$value, true];
        }

        if (is_string($value)) {
            if (strlen($value) > $this->maxValueBytes) {
                return [[
                    '_truncated' => true,
                    'type' => 'string',
                    'bytes' => strlen($value),
                ], true];
            }

            return [$value, true];
        }

        try {
            $json = json_encode(
                $value,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (Throwable) {
            $type = is_object($value) ? $value::class : gettype($value);

            return [['_type' => $type], true];
        }

        if (strlen($json) > $this->maxValueBytes) {
            $type = is_object($value) ? $value::class : gettype($value);

            return [[
                '_truncated' => true,
                'type' => $type,
                'bytes' => strlen($json),
            ], true];
        }

        return [json_decode($json, true), true];
    }

    private function pushErrorHandler(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            $convert = E_WARNING | E_USER_WARNING | E_RECOVERABLE_ERROR | E_USER_ERROR;
            if (($severity & $convert) === 0 || (error_reporting() & $severity) === 0) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });
    }

    private function armTimeout(int $timeoutMs): void
    {
        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal') || ! function_exists('pcntl_alarm')) {
            return;
        }

        $this->previousAlarmHandler = pcntl_signal_get_handler(SIGALRM);
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function (): void {
            throw new ProbeTimeoutException('probe timed out');
        });
        pcntl_alarm(max(1, (int) ceil($timeoutMs / 1000)));
    }

    private function disarmTimeout(): void
    {
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
        }

        if ($this->previousAlarmHandler !== null && function_exists('pcntl_signal')) {
            pcntl_signal(SIGALRM, $this->previousAlarmHandler);
            $this->previousAlarmHandler = null;
        }
    }
}
