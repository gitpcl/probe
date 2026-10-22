<?php

declare(strict_types=1);

namespace Probe\OpenApi;

use Probe\Support\Paths;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * An OpenAPI 3.x document and the operations it documents well enough to send:
 * a path whose parameters have examples, a request example when a body is
 * required, and a 2xx application/json schema to hold the answer against.
 */
final class Spec
{
    /** @var list<string> */
    public const CANDIDATES = [
        'openapi.yaml', 'openapi.yml', 'openapi.json',
        'docs/openapi.yaml', 'docs/openapi.yml', 'docs/openapi.json',
        'public/openapi.json', 'storage/api-docs/api-docs.json',
    ];

    /** @var list<string> */
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /** @param  array<string, mixed>  $document */
    private function __construct(
        public readonly string $file,
        private readonly array $document,
    ) {}

    /** The spec, or the reason there is none. */
    public static function load(string $root, ?string $configured): self|string
    {
        $candidates = $configured !== null && $configured !== '' ? [$configured] : self::CANDIDATES;
        foreach ($candidates as $candidate) {
            $path = str_starts_with($candidate, '/') ? $candidate : $root.'/'.$candidate;
            if (is_file($path)) {
                return self::parse($path, $root);
            }
        }

        return 'no OpenAPI spec found; looked for '.implode(', ', $candidates).' (contract.spec or PROBE_OPENAPI)';
    }

    private static function parse(string $path, string $root): self|string
    {
        $relative = Paths::relative($root, realpath($path) ?: $path);
        $text = (string) file_get_contents($path);

        if (str_ends_with($path, '.json')) {
            $document = json_decode($text, true);
        } elseif (! class_exists(Yaml::class)) {
            return $relative.' is YAML and symfony/yaml is not installed; use a JSON spec or composer require --dev symfony/yaml';
        } else {
            try {
                $document = Yaml::parse($text);
            } catch (Throwable $exception) {
                return $relative.' did not parse: '.$exception->getMessage();
            }
        }

        if (! is_array($document)) {
            return $relative.' is not an OpenAPI document';
        }

        if (! is_array($document['paths'] ?? null)) {
            return $relative.' has no paths';
        }

        return new self($relative, $document);
    }

    /**
     * @return list<array{label: string, method: string, path: string, uri: string, query: array<string, mixed>, body: mixed, status: int, schema: ?array<string, mixed>, skip: ?string}>
     */
    public function operations(): array
    {
        $operations = [];
        foreach ($this->document['paths'] as $path => $item) {
            if (! is_string($path) || ! is_array($item)) {
                continue;
            }

            $shared = is_array($item['parameters'] ?? null) ? $item['parameters'] : [];
            foreach (self::METHODS as $method) {
                if (is_array($item[$method] ?? null)) {
                    $operations[] = $this->operation(strtoupper($method), $path, $item[$method], $shared);
                }
            }
        }

        return $operations;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  list<mixed>  $shared
     * @return array{label: string, method: string, path: string, uri: string, query: array<string, mixed>, body: mixed, status: int, schema: ?array<string, mixed>, skip: ?string}
     */
    private function operation(string $method, string $path, array $operation, array $shared): array
    {
        $uri = $path;
        $query = [];
        $skip = null;

        $parameters = array_merge($shared, is_array($operation['parameters'] ?? null) ? $operation['parameters'] : []);
        foreach ($parameters as $parameter) {
            $parameter = $this->resolve($parameter);
            if (! is_array($parameter)) {
                continue;
            }

            $name = (string) ($parameter['name'] ?? '');
            $example = $this->example($parameter);
            if (($parameter['in'] ?? '') === 'path') {
                if ($example === null || is_array($example)) {
                    $skip ??= 'no example for path parameter '.$name;

                    continue;
                }

                $uri = str_replace('{'.$name.'}', (string) $example, $uri);
            } elseif (($parameter['in'] ?? '') === 'query' && $example !== null) {
                $query[$name] = $example;
            }
        }

        $body = null;
        $requestBody = $this->resolve($operation['requestBody'] ?? null);
        if (is_array($requestBody)) {
            $content = $requestBody['content']['application/json'] ?? null;
            $body = is_array($content) ? $this->example($content) : null;
            if ($body === null && ($requestBody['required'] ?? false) === true) {
                $skip ??= 'no example for the request body';
            }
        }

        [$status, $schema] = $this->success($operation);
        if ($schema === null) {
            $skip ??= 'no 2xx application/json response schema';
        }

        return [
            'label' => $method.' '.$path,
            'method' => $method,
            'path' => $path,
            'uri' => $uri,
            'query' => $query,
            'body' => $body,
            'status' => $status,
            'schema' => $schema,
            'skip' => $skip,
        ];
    }

    /**
     * The lowest 2xx response with a JSON schema.
     *
     * @param  array<string, mixed>  $operation
     * @return array{0: int, 1: ?array<string, mixed>}
     */
    private function success(array $operation): array
    {
        $responses = is_array($operation['responses'] ?? null) ? $operation['responses'] : [];
        $codes = [];
        foreach (array_keys($responses) as $code) {
            if (is_numeric($code) && (int) $code >= 200 && (int) $code < 300) {
                $codes[] = (int) $code;
            }
        }

        sort($codes);
        foreach ($codes as $code) {
            $response = $this->resolve($responses[$code] ?? $responses[(string) $code] ?? null);
            $schema = is_array($response) ? ($response['content']['application/json']['schema'] ?? null) : null;
            if (is_array($schema)) {
                return [$code, $schema];
            }
        }

        return [$codes[0] ?? 200, null];
    }

    /**
     * A parameter's or media type's example: example, the first of examples, then
     * the schema's example, default, or first enum value.
     *
     * @param  array<string, mixed>  $node
     */
    private function example(array $node): mixed
    {
        if (array_key_exists('example', $node)) {
            return $node['example'];
        }

        if (is_array($node['examples'] ?? null) && $node['examples'] !== []) {
            $first = $this->resolve(reset($node['examples']));
            if (is_array($first) && array_key_exists('value', $first)) {
                return $first['value'];
            }
        }

        $schema = $this->resolve($node['schema'] ?? null);
        if (! is_array($schema)) {
            return null;
        }

        foreach (['example', 'default'] as $key) {
            if (array_key_exists($key, $schema)) {
                return $schema[$key];
            }
        }

        if (is_array($schema['enum'] ?? null) && $schema['enum'] !== []) {
            return $schema['enum'][0];
        }

        return null;
    }

    /** Follow local $ref pointers (#/components/...), a few hops at most. */
    public function resolve(mixed $node): mixed
    {
        for ($hop = 0; $hop < 10 && is_array($node) && is_string($node['$ref'] ?? null); $hop++) {
            $node = $this->pointer($node['$ref']);
        }

        return $node;
    }

    private function pointer(string $ref): mixed
    {
        if (! str_starts_with($ref, '#/')) {
            return null;
        }

        $node = $this->document;
        foreach (explode('/', substr($ref, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return $node;
    }
}
