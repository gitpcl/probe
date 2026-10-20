<?php

declare(strict_types=1);

namespace Probe\Selective;

use DOMDocument;
use DOMElement;

/** Reads a PHPUnit or Pest JUnit file into compact failure rows. */
final class JunitReport
{
    /**
     * @return array{failures: list<array{id: string, message: string, type: string, file: string}>, times: array<string, int>, tests: int}
     */
    public static function parse(string $xml): array
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

            $file = $case->getAttribute('file');
            $seconds = (float) $case->getAttribute('time');
            if ($file !== '') {
                $times[$file] = (int) round($seconds * 1000);
            }

            $message = self::failureMessage($case);
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

    private static function failureMessage(DOMElement $case): ?string
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

            $message = trim($node->getAttribute('message'));
            if ($message === '') {
                $message = trim($node->textContent);
            }

            $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

            return $message === '' ? $tag : $message;
        }

        return null;
    }
}
