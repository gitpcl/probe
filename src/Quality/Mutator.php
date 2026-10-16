<?php

declare(strict_types=1);

namespace Probe\Quality;

/** One-token source mutants. Each mutant is a single operator or boolean flip. */
final class Mutator
{
    /** @var array<string, string> */
    private const MAP = [
        '+' => '-',
        '-' => '+',
        '*' => '/',
        '/' => '*',
        '==' => '!=',
        '!=' => '==',
        '===' => '!==',
        '!==' => '===',
        '<' => '<=',
        '<=' => '<',
        '>' => '>=',
        '>=' => '>',
        '&&' => '||',
        '||' => '&&',
        'and' => 'or',
        'or' => 'and',
        'true' => 'false',
        'false' => 'true',
    ];

    /**
     * @return list<array{line: int, from: string, to: string, code: string}>
     */
    public function mutants(string $source, int $limit): array
    {
        $limit = max(1, $limit);
        $tokens = token_get_all($source);
        $mutants = [];

        foreach ($tokens as $index => $token) {
            if (count($mutants) >= $limit) {
                break;
            }

            $text = is_array($token) ? $token[1] : $token;
            $id = is_array($token) ? $token[0] : null;
            if (in_array($id, [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_CONSTANT_ENCAPSED_STRING, T_WHITESPACE], true)) {
                continue;
            }

            $replacement = self::MAP[$text] ?? self::MAP[strtolower($text)] ?? null;
            if ($replacement === null) {
                continue;
            }

            if (($text === '-' || $text === '+') && $this->isUnary($tokens, $index)) {
                continue;
            }

            $clone = $tokens;
            $clone[$index] = is_array($token)
                ? [$token[0], $replacement, $token[2]]
                : $replacement;

            $mutants[] = [
                'line' => $this->lineAt($tokens, $index),
                'from' => $text,
                'to' => $replacement,
                'code' => $this->render($clone),
            ];
        }

        return $mutants;
    }

    /** @param  list<array{0?: int, 1?: string, 2?: int}|string>  $tokens */
    private function render(array $tokens): string
    {
        $source = '';
        foreach ($tokens as $token) {
            $source .= is_array($token) ? $token[1] : $token;
        }

        return $source;
    }

    /** @param  list<array{0?: int, 1?: string, 2?: int}|string>  $tokens */
    private function lineAt(array $tokens, int $index): int
    {
        $token = $tokens[$index];
        if (is_array($token) && isset($token[2])) {
            return (int) $token[2];
        }

        $line = 1;
        for ($cursor = 0; $cursor < $index; $cursor++) {
            $text = is_array($tokens[$cursor]) ? $tokens[$cursor][1] : $tokens[$cursor];
            $line += substr_count($text, "\n");
        }

        return $line;
    }

    /** @param  list<array{0?: int, 1?: string, 2?: int}|string>  $tokens */
    private function isUnary(array $tokens, int $index): bool
    {
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            $token = $tokens[$cursor];
            $id = is_array($token) ? $token[0] : null;
            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }

            $text = is_array($token) ? $token[1] : $token;

            return in_array($text, ['(', ',', '=', '[', '{', '?', ':', '<', '>', '+', '-', '*', '/', '&', '|', '!'], true);
        }

        return true;
    }
}
