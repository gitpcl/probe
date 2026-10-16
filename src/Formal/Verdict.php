<?php

declare(strict_types=1);

namespace Probe\Formal;

/**
 * Turns prover stdout into a pass or a normal failure.
 * The trace is the counterexample text for get_failures.
 */
final class Verdict
{
    /**
     * @return array{status: string, message: string, trace: string}
     */
    public static function interpret(string $bridge, int $exit, string $stdout, string $stderr): array
    {
        $text = trim($stdout."\n".$stderr);
        $flat = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        if ($exit === 127 || str_contains(strtolower($text), 'command not found')) {
            if (! self::hasCounterexample($bridge, $text)) {
                return [
                    'status' => 'error',
                    'message' => $bridge.' was not found',
                    'trace' => $text,
                ];
            }
        }

        return match ($bridge) {
            'alloy' => self::alloy($exit, $text, $flat),
            'tla' => self::tla($exit, $text, $flat),
            'dafny' => self::dafny($exit, $text, $flat),
            'lean' => self::lean($exit, $text, $flat),
            default => $exit === 0
                ? ['status' => 'passed', 'message' => '', 'trace' => '']
                : ['status' => 'failed', 'message' => $flat !== '' ? $flat : $bridge.' exited '.$exit, 'trace' => $text],
        };
    }

    /** @return array{status: string, message: string, trace: string} */
    private static function alloy(int $exit, string $text, string $flat): array
    {
        if (self::alloyCounterexample($text)) {
            return ['status' => 'failed', 'message' => 'Counterexample found', 'trace' => $text];
        }

        if ($exit === 0 || stripos($text, 'No counterexample') !== false) {
            return ['status' => 'passed', 'message' => '', 'trace' => ''];
        }

        return ['status' => 'failed', 'message' => $flat !== '' ? $flat : 'alloy exited '.$exit, 'trace' => $text];
    }

    /** @return array{status: string, message: string, trace: string} */
    private static function tla(int $exit, string $text, string $flat): array
    {
        if (preg_match('/Invariant .+ is violated/i', $text, $match) === 1 || preg_match('/^Error:.+$/mi', $text, $match) === 1) {
            return ['status' => 'failed', 'message' => trim($match[0]), 'trace' => $text];
        }

        if ($exit === 0 || stripos($text, 'No error has been found') !== false) {
            return ['status' => 'passed', 'message' => '', 'trace' => ''];
        }

        return ['status' => 'failed', 'message' => $flat !== '' ? $flat : 'tlc exited '.$exit, 'trace' => $text];
    }

    /** @return array{status: string, message: string, trace: string} */
    private static function dafny(int $exit, string $text, string $flat): array
    {
        if (preg_match('/(\d+)\s+error/', $text, $match) === 1 && (int) $match[1] > 0) {
            return ['status' => 'failed', 'message' => 'Dafny reported '.$match[1].' error(s)', 'trace' => $text];
        }

        if ($exit === 0) {
            return ['status' => 'passed', 'message' => '', 'trace' => ''];
        }

        return ['status' => 'failed', 'message' => $flat !== '' ? $flat : 'dafny exited '.$exit, 'trace' => $text];
    }

    /** @return array{status: string, message: string, trace: string} */
    private static function lean(int $exit, string $text, string $flat): array
    {
        if (preg_match('/error:.+$/mi', $text, $match) === 1) {
            return ['status' => 'failed', 'message' => trim($match[0]), 'trace' => $text];
        }

        if ($exit === 0) {
            return ['status' => 'passed', 'message' => '', 'trace' => ''];
        }

        return ['status' => 'failed', 'message' => $flat !== '' ? $flat : 'lean exited '.$exit, 'trace' => $text];
    }

    private static function alloyCounterexample(string $text): bool
    {
        return preg_match('/(?<!\bno )counterexample found/i', $text) === 1;
    }

    private static function hasCounterexample(string $bridge, string $text): bool
    {
        return match ($bridge) {
            'alloy' => self::alloyCounterexample($text),
            'tla' => preg_match('/Invariant .+ is violated|^Error:/mi', $text) === 1,
            'dafny' => preg_match('/(\d+)\s+error/', $text, $match) === 1 && (int) $match[1] > 0,
            'lean' => preg_match('/error:/i', $text) === 1,
            default => false,
        };
    }
}
