<?php

declare(strict_types=1);

namespace Probe\Quality;

use Probe\Contract\QualityCheck;
use Probe\Result\ProbeResult;
use Probe\Runner\SnippetRunner;

/** Property and metamorphic checks. The snippet must return true for every generated case. */
final class PropertyCheck implements QualityCheck
{
    public function __construct(
        private readonly SnippetRunner $snippets,
        private readonly int $cases = 20,
        private readonly int $seed = 1,
    ) {}

    /** @param  array<string, mixed>  $request */
    public function check(array $request): array
    {
        return $this->run($request)->toArray();
    }

    /** @param  array<string, mixed>  $request */
    public function run(array $request): ProbeResult
    {
        $code = $request['code'] ?? null;
        $preset = isset($request['property']) && is_string($request['property']) ? $request['property'] : '';
        if ((! is_string($code) || trim($code) === '') && $preset === '') {
            return ProbeResult::error('code', 'property_check needs code or a property name');
        }

        if (! is_string($code) || trim($code) === '') {
            $code = $this->preset($preset);
            if ($code === null) {
                return ProbeResult::error('property', 'unknown property '.$preset);
            }
        }

        $cases = isset($request['cases']) ? (int) $request['cases'] : $this->cases;
        $seed = isset($request['seed']) ? (int) $request['seed'] : $this->seed;
        $samples = (new SampleGenerator($seed))->cases(max(1, $cases));
        $snippet = $this->snippet($code, $samples);
        $timeout = isset($request['timeout_ms']) ? (int) $request['timeout_ms'] : null;
        $result = $this->snippets->run($snippet, $timeout);

        if ($result->status === 'passed' && is_array($result->value)) {
            return $result;
        }

        return $result;
    }

    /** @param  list<array{input: mixed, a: int, b: int}>  $cases */
    public function snippet(string $code, array $cases): string
    {
        [$uses, $body] = probe_hoist_uses($code);
        $exported = var_export($cases, true);
        $prefix = $uses === [] ? '' : implode("\n", $uses)."\n";

        return $prefix.<<<PHP
\$__cases = {$exported};
foreach (\$__cases as \$__index => \$__case) {
    \$input = \$__case['input'];
    \$a = \$__case['a'];
    \$b = \$__case['b'];
    \$ok = (static function () use (\$input, \$a, \$b) {
{$body}
    })();
    if (\$ok !== true) {
        \$encoded = json_encode(['case' => \$__index, 'input' => \$input, 'a' => \$a, 'b' => \$b], JSON_UNESCAPED_SLASHES);
        throw new RuntimeException('property failed '.\$encoded);
    }
}
return ['cases' => count(\$__cases)];
PHP;
    }

    private function preset(string $name): ?string
    {
        return match ($name) {
            'strlen_reverse' => 'return strlen((string) $input) === strlen(strrev((string) $input));',
            'sort_idempotent' => <<<'PHP'
$list = is_array($input) ? $input : [$input];
$once = $list;
sort($once);
$twice = $once;
sort($twice);
return $once === $twice;
PHP,
            'addition_commutative' => 'return $a + $b === $b + $a;',
            default => null,
        };
    }
}
