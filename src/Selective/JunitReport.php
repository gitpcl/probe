<?php

declare(strict_types=1);

namespace Probe\Selective;

use DOMDocument;
use DOMElement;
use Probe\Support\Paths;

/** Reads a PHPUnit or Pest JUnit file into compact failure rows. */
final class JunitReport
{
    /**
     * @return array{failures: list<array{id: string, message: string, type: string, file: string}>, times: array<string, int>, tests: int}
     */
    public static function parse(string $xml, string $root = ''): array
    {
        $empty = ['failures' => [], 'times' => [], 'tests' => 0];
        if (trim($xml) === '') {
            return $empty;
        }

        $document = new DOMDocument;
        if (@$document->loadXML($xml) === false) {
            return $empty;
        }

        $failures = [];
        $times = [];
        $tests = 0;

        foreach ($document->getElementsByTagName('testcase') as $case) {
            $tests++;
            $name = $case->getAttribute('classname');
            if ($name === '') {
                $name = $case->getAttribute('name');
            } else {
                $name .= '::'.$case->getAttribute('name');
            }

            // Pest appends "::test name" to the file attribute; durations are per file, summed over its cases.
            $file = (string) $case->getAttribute('file');
            $file = (($at = strpos($file, '::')) !== false) ? substr($file, 0, $at) : $file;
            $seconds = (float) $case->getAttribute('time');
            if ($file !== '') {
                $times[$file] = ($times[$file] ?? 0) + (int) round($seconds * 1000);
            }

            $message = self::failureMessage($case, $name, $root);
            if ($message === null) {
                continue;
            }

            $failures[] = [
                'id' => (string) (count($failures) + 1),
                'message' => $name.': '.$message,
                'type' => 'test',
                'file' => $file !== '' ? $file : $name,
            ];
        }

        return ['failures' => $failures, 'times' => $times, 'tests' => $tests];
    }

    /**
     * The assertion message and the test's own frames. The runner prints the
     * test name first and the whole stack last; a forked shard's stack runs
     * down through the worker, so frames stop at the first one under vendor/
     * or outside the project.
     */
    private static function failureMessage(DOMElement $case, string $name, string $root): ?string
    {
        foreach (['failure', 'error'] as $tag) {
            $nodes = $case->getElementsByTagName($tag);
            if ($nodes->length === 0) {
                continue;
            }

            $node = $nodes->item(0);
            if (! $node instanceof DOMElement) {
                continue;
            }

            $text = trim($node->getAttribute('message'));
            if ($text === '') {
                $text = trim($node->textContent);
            }

            [$message, $frames] = self::split(self::withoutName($text, $case, $name), $root);
            $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
            if ($message === '') {
                $message = $tag;
            }

            return $frames === [] ? $message : $message.' at '.implode(', ', $frames);
        }

        return null;
    }

    /** PHPUnit puts the test name on its own first line; Pest glues its description to the message. */
    private static function withoutName(string $text, DOMElement $case, string $name): string
    {
        $short = $case->getAttribute('name');
        $class = $case->getAttribute('classname');
        $candidates = [$name, str_replace('.', '\\', $class).'::'.$short, $short];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && $candidate !== '::' && str_starts_with($text, $candidate)) {
                return ltrim(substr($text, strlen($candidate)), " \t\r\n:");
            }
        }

        return $text;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private static function split(string $text, string $root): array
    {
        $message = [];
        $frames = [];

        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            // A frame is a path with a line number; the Pest and PHPUnit binaries have no extension.
            if (preg_match('/^(?:#\d+\s+)?(?:at\s+)?([^\s:(]*\/[^\s:(]+)(?::(\d+)|\((\d+)\))/', $line, $match) !== 1) {
                if ($frames === []) {
                    $message[] = $line;
                }

                continue;
            }

            $file = $match[1];
            $relative = $root === '' ? $file : Paths::relative($root, $file);
            $outside = $root !== '' && $relative === $file && str_starts_with($file, '/');
            if ($outside || str_starts_with($relative, 'vendor/') || str_contains($relative, '/vendor/')) {
                break;
            }

            $frames[] = $relative.':'.($match[2] !== '' ? $match[2] : ($match[3] ?? ''));
        }

        return [implode("\n", $message), $frames];
    }
}
