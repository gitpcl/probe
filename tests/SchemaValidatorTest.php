<?php

declare(strict_types=1);

namespace Probe\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Probe\OpenApi\SchemaValidator;

final class SchemaValidatorTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    private const COMPONENTS = [
        'User' => [
            'type' => 'object',
            'required' => ['id', 'email'],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'email' => ['type' => 'string', 'minLength' => 3, 'pattern' => '^[^@]+@[^@]+$'],
                'name' => ['type' => 'string', 'nullable' => true],
                'role' => ['type' => 'string', 'enum' => ['admin', 'member']],
            ],
            'additionalProperties' => false,
        ],
    ];

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $expected
     */
    #[DataProvider('cases')]
    public function test_each_keyword_reports_what_the_body_got_wrong(array $schema, string $json, array $expected): void
    {
        $validator = new SchemaValidator(self::resolver(...));

        $this->assertSame($expected, $validator->validate(json_decode($json), $schema));
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string, 2: list<string>}> */
    public static function cases(): iterable
    {
        $user = ['$ref' => '#/components/schemas/User'];

        yield 'valid user through $ref' => [$user, '{"id":1,"email":"a@b.c","name":null,"role":"admin"}', []];
        yield 'null where not nullable' => [$user, '{"id":1,"email":null}', ['$.email: expected string, got null']];
        yield 'missing required' => [$user, '{"id":1}', ['$.email: required property missing']];
        yield 'extra property' => [$user, '{"id":1,"email":"a@b.c","age":3}', ['$.age: property not allowed']];
        yield 'wrong scalar types' => [$user, '{"id":"1","email":7}', ['$.id: expected integer, got string', '$.email: expected string, got integer']];
        yield 'enum' => [$user, '{"id":1,"email":"a@b.c","role":"owner"}', ['$.role: "owner" is not one of ["admin","member"]']];
        yield 'minimum and pattern' => [$user, '{"id":0,"email":"nope"}', ['$.id: 0 is below the minimum 1', '$.email: "nope" does not match ^[^@]+@[^@]+$']];
        yield 'list items with pointer' => [
            ['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'minItems' => 1, 'items' => $user]]],
            '{"data":[{"id":1,"email":"a@b.c"},{"id":2,"email":null}]}',
            ['$.data[1].email: expected string, got null'],
        ];
        yield 'empty list below minItems' => [
            ['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'minItems' => 1]]],
            '{"data":[]}',
            ['$.data: 0 items, expected at least 1'],
        ];
        yield 'object where array expected' => [['type' => 'array'], '{"a":1}', ['$: expected array, got object']];
        yield 'type list with null' => [['type' => ['string', 'null']], 'null', []];
        yield 'oneOf no match' => [
            ['oneOf' => [['type' => 'integer'], ['type' => 'boolean']]],
            '"x"',
            ['$: matches 0 of oneOf, expected exactly 1'],
        ];
        yield 'oneOf two matches' => [
            ['oneOf' => [['type' => 'number'], ['type' => 'integer']]],
            '3',
            ['$: matches 2 of oneOf, expected exactly 1'],
        ];
        yield 'anyOf passes on one' => [['anyOf' => [['type' => 'integer'], ['type' => 'string']]], '"x"', []];
        yield 'allOf reports the failing part' => [
            ['allOf' => [['type' => 'object', 'required' => ['a']], ['type' => 'object', 'required' => ['b']]]],
            '{"a":1}',
            ['$.b: required property missing'],
        ];
        yield 'maxLength and maximum' => [
            ['type' => 'object', 'properties' => ['s' => ['type' => 'string', 'maxLength' => 2], 'n' => ['type' => 'number', 'maximum' => 1.5]]],
            '{"s":"abc","n":2}',
            ['$.s: 3 characters, expected at most 2', '$.n: 2 is above the maximum 1.5'],
        ];
        yield 'format is ignored' => [['type' => 'string', 'format' => 'date-time'], '"not a date"', []];
    }

    public function test_the_limit_caps_the_messages(): void
    {
        $validator = new SchemaValidator(self::resolver(...), 2);
        $schema = ['type' => 'array', 'items' => ['type' => 'integer']];

        $this->assertCount(2, $validator->validate(json_decode('["a","b","c","d"]'), $schema));
    }

    private static function resolver(mixed $node): mixed
    {
        while (is_array($node) && is_string($node['$ref'] ?? null)) {
            $name = substr($node['$ref'], strlen('#/components/schemas/'));
            $node = self::COMPONENTS[$name] ?? null;
        }

        return $node;
    }
}
