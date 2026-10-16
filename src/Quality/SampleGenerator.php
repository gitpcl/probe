<?php

declare(strict_types=1);

namespace Probe\Quality;

/** Deterministic samples for property checks. The same seed yields the same cases. */
final class SampleGenerator
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = $seed !== 0 ? $seed : 1;
    }

    /** @return list<array{input: mixed, a: int, b: int}> */
    public function cases(int $count): array
    {
        $count = max(1, $count);
        $cases = [];

        for ($index = 0; $index < $count; $index++) {
            $a = ($this->next() % 101) - 50;
            $b = ($this->next() % 101) - 50;
            $length = $this->next() % 8;
            $chars = '';
            for ($cursor = 0; $cursor < $length; $cursor++) {
                $chars .= chr(97 + ($this->next() % 26));
            }

            $list = [];
            $size = $this->next() % 5;
            for ($cursor = 0; $cursor < $size; $cursor++) {
                $list[] = ($this->next() % 21) - 10;
            }

            $cases[] = [
                'input' => $index % 2 === 0 ? $chars : $list,
                'a' => $a,
                'b' => $b,
            ];
        }

        return $cases;
    }

    private function next(): int
    {
        $value = $this->state;
        $value ^= ($value << 13) & 0xFFFFFFFF;
        $value ^= ($value >> 17);
        $value ^= ($value << 5) & 0xFFFFFFFF;
        $this->state = $value & 0x7FFFFFFF;
        if ($this->state === 0) {
            $this->state = 1;
        }

        return $this->state;
    }
}
