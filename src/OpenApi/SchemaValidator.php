<?php

declare(strict_types=1);

namespace Probe\OpenApi;

use Closure;
use stdClass;

/**
 * The part of JSON Schema that OpenAPI responses use, checked against a decoded
 * body (objects as stdClass, arrays as lists). Messages name the JSON pointer and
 * what was found, nothing else. format is not checked.
 */
final class SchemaValidator
{
    /** @param  Closure(mixed): mixed  $resolve  follows $ref */
    public function __construct(
        private readonly Closure $resolve,
        private readonly int $limit = 10,
    ) {}

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    public function validate(mixed $data, array $schema, string $at = '$'): array
    {
        $errors = [];
        $this->check($data, $schema, $at, $errors, 0);

        return $errors;
    }

    /** @param  list<string>  $errors */
    private function check(mixed $data, mixed $schema, string $at, array &$errors, int $depth): void
    {
        if (count($errors) >= $this->limit || $depth > 32) {
            return;
        }

        $schema = ($this->resolve)($schema);
        if (! is_array($schema)) {
            return;
        }

        if ($data === null && ($schema['nullable'] ?? false) === true) {
            return;
        }

        if (! $this->combinations($data, $schema, $at, $errors, $depth)) {
            return;
        }

        $types = $this->types($schema);
        if ($types !== [] && ! self::isType($data, $types)) {
            $errors[] = $at.': expected '.implode(' or ', $types).', got '.self::describe($data);

            return;
        }

        if (is_array($schema['enum'] ?? null) && ! in_array($data, $schema['enum'], true)) {
            $errors[] = $at.': '.self::describe($data, true).' is not one of '.json_encode($schema['enum'], JSON_UNESCAPED_SLASHES);

            return;
        }

        if ($data instanceof stdClass) {
            $this->object($data, $schema, $at, $errors, $depth);
        } elseif (is_array($data)) {
            $this->list($data, $schema, $at, $errors, $depth);
        } elseif (is_string($data)) {
            $this->string($data, $schema, $at, $errors);
        } elseif (is_int($data) || is_float($data)) {
            $this->number($data, $schema, $at, $errors);
        }
    }

    /**
     * allOf, anyOf, oneOf. Returns false when a failure there ends the check.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $errors
     */
    private function combinations(mixed $data, array $schema, string $at, array &$errors, int $depth): bool
    {
        foreach (['allOf', 'anyOf', 'oneOf'] as $key) {
            $candidates = $schema[$key] ?? null;
            if (! is_array($candidates) || $candidates === []) {
                continue;
            }

            $matches = 0;
            $first = [];
            foreach ($candidates as $candidate) {
                $own = [];
                $this->check($data, $candidate, $at, $own, $depth + 1);
                if ($own === []) {
                    $matches++;
                } elseif ($first === []) {
                    $first = $own;
                }
            }

            if ($key === 'allOf' && $matches < count($candidates)) {
                $errors = array_merge($errors, $first);

                return false;
            }

            if ($key === 'anyOf' && $matches === 0) {
                $errors[] = $at.': matches none of anyOf ('.$first[0].')';

                return false;
            }

            if ($key === 'oneOf' && $matches !== 1) {
                $errors[] = $at.': matches '.$matches.' of oneOf, expected exactly 1';

                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $errors
     */
    private function object(stdClass $data, array $schema, string $at, array &$errors, int $depth): void
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

        foreach ($required as $name) {
            if (is_string($name) && ! property_exists($data, $name)) {
                $errors[] = $at.'.'.$name.': required property missing';
            }
        }

        foreach ($properties as $name => $property) {
            if (property_exists($data, (string) $name)) {
                $this->check($data->{$name}, $property, $at.'.'.$name, $errors, $depth + 1);
            }
        }

        if (($schema['additionalProperties'] ?? true) === false) {
            foreach (array_keys(get_object_vars($data)) as $name) {
                if (! array_key_exists($name, $properties)) {
                    $errors[] = $at.'.'.$name.': property not allowed';
                }
            }
        }
    }

    /**
     * @param  list<mixed>  $data
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $errors
     */
    private function list(array $data, array $schema, string $at, array &$errors, int $depth): void
    {
        $count = count($data);
        if (isset($schema['minItems']) && $count < (int) $schema['minItems']) {
            $errors[] = $at.': '.$count.' items, expected at least '.(int) $schema['minItems'];
        }

        if (isset($schema['maxItems']) && $count > (int) $schema['maxItems']) {
            $errors[] = $at.': '.$count.' items, expected at most '.(int) $schema['maxItems'];
        }

        if (! is_array($schema['items'] ?? null)) {
            return;
        }

        foreach ($data as $index => $item) {
            $this->check($item, $schema['items'], $at.'['.$index.']', $errors, $depth + 1);
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $errors
     */
    private function string(string $data, array $schema, string $at, array &$errors): void
    {
        $length = function_exists('mb_strlen') ? mb_strlen($data) : strlen($data);
        if (isset($schema['minLength']) && $length < (int) $schema['minLength']) {
            $errors[] = $at.': '.$length.' characters, expected at least '.(int) $schema['minLength'];
        }

        if (isset($schema['maxLength']) && $length > (int) $schema['maxLength']) {
            $errors[] = $at.': '.$length.' characters, expected at most '.(int) $schema['maxLength'];
        }

        $pattern = $schema['pattern'] ?? null;
        if (is_string($pattern) && $pattern !== '' && @preg_match('/'.str_replace('/', '\/', $pattern).'/u', $data) === 0) {
            $errors[] = $at.': '.json_encode($data, JSON_UNESCAPED_SLASHES).' does not match '.$pattern;
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $errors
     */
    private function number(int|float $data, array $schema, string $at, array &$errors): void
    {
        if (isset($schema['minimum']) && is_numeric($schema['minimum']) && $data < $schema['minimum']) {
            $errors[] = $at.': '.$data.' is below the minimum '.$schema['minimum'];
        }

        if (isset($schema['maximum']) && is_numeric($schema['maximum']) && $data > $schema['maximum']) {
            $errors[] = $at.': '.$data.' is above the maximum '.$schema['maximum'];
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function types(array $schema): array
    {
        $type = $schema['type'] ?? null;
        $types = is_array($type) ? array_values(array_filter($type, 'is_string')) : (is_string($type) ? [$type] : []);
        if ($types !== [] && ($schema['nullable'] ?? false) === true && ! in_array('null', $types, true)) {
            $types[] = 'null';
        }

        return $types;
    }

    /** @param  list<string>  $types */
    private static function isType(mixed $data, array $types): bool
    {
        foreach ($types as $type) {
            $matches = match ($type) {
                'object' => $data instanceof stdClass,
                'array' => is_array($data),
                'string' => is_string($data),
                'integer' => is_int($data),
                'number' => is_int($data) || is_float($data),
                'boolean' => is_bool($data),
                'null' => $data === null,
                default => true,
            };
            if ($matches) {
                return true;
            }
        }

        return false;
    }

    private static function describe(mixed $data, bool $withValue = false): string
    {
        $type = match (true) {
            $data === null => 'null',
            $data instanceof stdClass => 'object',
            is_array($data) => 'array',
            is_string($data) => 'string',
            is_int($data) => 'integer',
            is_float($data) => 'number',
            is_bool($data) => 'boolean',
            default => gettype($data),
        };

        if (! $withValue || $data instanceof stdClass || is_array($data)) {
            return $type;
        }

        return json_encode($data, JSON_UNESCAPED_SLASHES) ?: $type;
    }
}
