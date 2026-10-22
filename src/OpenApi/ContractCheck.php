<?php

declare(strict_types=1);

namespace Probe\OpenApi;

use Closure;
use Probe\Result\Failure;
use Probe\Result\ProbeResult;
use Probe\Runner\SnippetRunner;
use Probe\Selective\DependencyGraph;
use Probe\Selective\GitChanges;
use Probe\Support\Stats;

/**
 * contract_check. Each documented operation goes through the booted application
 * as a probe (so it runs in the fork and its writes roll back), and the JSON that
 * comes back is held against the spec's response schema.
 */
final class ContractCheck
{
    /** @param  array<string, mixed>  $config  the contract block */
    public function __construct(
        private readonly string $root,
        private readonly SnippetRunner $snippets,
        private readonly DependencyGraph $graph,
        private readonly ?Closure $routes,
        private readonly string $home,
        private readonly array $config = [],
    ) {}

    /** @param  array<string, mixed>  $request */
    public function run(array $request): ProbeResult
    {
        $started = hrtime(true);

        $configured = $request['spec'] ?? ($this->config['spec'] ?? null);
        $spec = Spec::load($this->root, is_string($configured) ? $configured : null);
        if (is_string($spec)) {
            return ProbeResult::error('spec', $spec);
        }

        [$operations, $selection, $problem] = $this->select($spec, $request);
        if ($problem !== null) {
            return ProbeResult::error('routes', $problem);
        }

        $limit = max(1, (int) ($this->config['limit'] ?? 10));
        $validator = new SchemaValidator($spec->resolve(...), $limit);
        $auth = $request['auth'] ?? ($this->config['auth'] ?? null);
        $budget = (int) ($this->config['timeout_ms'] ?? 60000);

        $failures = [];
        $skipped = [];
        $checked = 0;
        foreach ($operations as $index => $operation) {
            if ($operation['skip'] !== null) {
                $skipped[] = ['operation' => $operation['label'], 'reason' => $operation['skip']];

                continue;
            }

            if (Stats::elapsedMs($started) > $budget - 5000) {
                $skipped[] = ['operation' => $operation['label'], 'reason' => 'out of time'];

                continue;
            }

            $checked++;
            $bodyFile = $this->home.'/contract-'.getmypid().'-'.$index.'.body';
            $result = $this->snippets->run($this->snippet($operation, $bodyFile, is_string($auth) || is_int($auth) ? (string) $auth : null));
            $body = (string) @file_get_contents($bodyFile);
            @unlink($bodyFile);

            foreach ($this->verdict($operation, $result, $body, $validator) as $message) {
                $failures[] = new Failure(id: (string) (count($failures) + 1), message: $message, type: 'contract', file: $spec->file);
            }
        }

        $value = [
            'operations' => count($operations),
            'checked' => $checked,
            'failed' => count($failures),
            'selection' => $selection,
            'spec' => $spec->file,
        ] + ($skipped === [] ? [] : ['skipped' => array_slice($skipped, 0, 10)]);

        if ($checked === 0) {
            return ProbeResult::error('examples', 'no operation could be sent: '.($skipped[0]['reason'] ?? 'nothing documented'), Stats::elapsedMs($started));
        }

        return new ProbeResult(
            status: $failures === [] ? 'passed' : 'failed',
            durationMs: Stats::elapsedMs($started),
            failures: $failures,
            value: $value,
            hasValue: true,
        );
    }

    /**
     * @param  array{label: string, status: int, schema: ?array<string, mixed>}  $operation
     * @return list<string>
     */
    private function verdict(array $operation, ProbeResult $result, string $body, SchemaValidator $validator): array
    {
        if ($result->status !== 'passed') {
            return [$operation['label'].': '.($result->failures[0]->message ?? 'the request did not complete')];
        }

        $status = is_array($result->value) ? (int) ($result->value['status'] ?? 0) : 0;
        if ($status !== $operation['status']) {
            $excerpt = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');

            return [$operation['label'].': expected '.$operation['status'].', got '.$status.($excerpt === '' ? '' : ' '.substr($excerpt, 0, 120))];
        }

        $decoded = json_decode($body);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $type = is_array($result->value) ? (string) ($result->value['type'] ?? '') : '';

            return [$operation['label'].' '.$status.': response is not JSON'.($type === '' ? '' : ' ('.$type.')')];
        }

        $messages = [];
        foreach ($validator->validate($decoded, $operation['schema'] ?? []) as $message) {
            $messages[] = $operation['label'].' '.$status.': '.$message;
        }

        return $messages;
    }

    /**
     * @param  array{method: string, uri: string, query: array<string, mixed>, body: mixed}  $operation
     */
    private function snippet(array $operation, string $bodyFile, ?string $auth): string
    {
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        $headers = is_array($this->config['headers'] ?? null) ? $this->config['headers'] : [];
        foreach ($headers as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = (string) $value;
            }
        }

        $content = $operation['body'] === null ? 'null' : var_export(json_encode($operation['body'], JSON_UNESCAPED_SLASHES), true);
        $login = $auth === null ? '' : <<<PHP
\$__auth = {$this->export($auth)};
\$__model = (string) config('auth.providers.users.model');
\$__user = is_numeric(\$__auth) ? \$__model::find((int) \$__auth) : \$__model::where('email', \$__auth)->first();
if (\$__user === null) {
    throw new RuntimeException('contract auth user '.\$__auth.' was not found');
}
auth()->login(\$__user);

PHP;

        return <<<PHP
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

{$login}\$__kernel = app(Kernel::class);
\$__request = Request::create({$this->export($operation['uri'])}, {$this->export($operation['method'])}, {$this->export($operation['query'])}, [], [], {$this->export($server)}, {$content});
\$__response = \$__kernel->handle(\$__request);
\$__kernel->terminate(\$__request, \$__response);
file_put_contents({$this->export($bodyFile)}, (string) \$__response->getContent());
return ['status' => \$__response->getStatusCode(), 'type' => (string) \$__response->headers->get('content-type')];
PHP;
    }

    private function export(mixed $value): string
    {
        return var_export($value, true);
    }

    /**
     * The operations to send: those named by routes, those reached by the changed
     * files through the route map, or all of them.
     *
     * @param  array<string, mixed>  $request
     * @return array{0: list<array{label: string, method: string, path: string, uri: string, query: array<string, mixed>, body: mixed, status: int, schema: ?array<string, mixed>, skip: ?string}>, 1: string, 2: ?string}
     */
    private function select(Spec $spec, array $request): array
    {
        $all = $spec->operations();

        $routes = $request['routes'] ?? null;
        if (is_string($routes) && $routes !== '') {
            $routes = explode(',', $routes);
        }

        if (is_array($routes) && $routes !== []) {
            $wanted = [];
            foreach ($routes as $route) {
                if (! is_string($route) || trim($route) === '') {
                    continue;
                }

                $parts = preg_split('/\s+/', trim($route), 2) ?: [];
                $wanted[] = count($parts) === 2 ? [strtoupper($parts[0]), self::shape($parts[1])] : [null, self::shape($parts[0])];
            }

            $picked = array_values(array_filter($all, static function (array $operation) use ($wanted): bool {
                foreach ($wanted as [$method, $shape]) {
                    if ($shape === self::shape($operation['path']) && ($method === null || $method === $operation['method'])) {
                        return true;
                    }
                }

                return false;
            }));

            return [$picked, 'given', $picked === [] ? 'no operation in '.$spec->file.' matches the given routes' : null];
        }

        $paths = $request['paths'] ?? null;
        if (is_array($paths) || (is_string($paths) && $paths !== '')) {
            $paths = GitChanges::requested($this->root, $paths);
            $shapes = array_map(self::shape(...), $this->urisFor($paths));
            $picked = array_values(array_filter($all, static fn (array $operation): bool => in_array(self::shape($operation['path']), $shapes, true)));

            return [$picked, 'affected', $picked === [] ? 'no documented route reaches '.implode(', ', $paths) : null];
        }

        return [$all, 'all', $all === [] ? $spec->file.' documents no operations' : null];
    }

    /**
     * Route URIs served by the classes the changed files reach.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function urisFor(array $paths): array
    {
        if ($this->routes === null) {
            return [];
        }

        $classes = [];
        $real = realpath($this->root) ?: $this->root;
        foreach ($paths as $path) {
            $absolute = str_starts_with($path, '/') ? $path : $real.'/'.ltrim($path, '/');
            foreach ($this->graph->classesIn($absolute) as $class) {
                $classes[] = $class;
            }
        }

        $map = ($this->routes)();
        $uris = [];
        foreach ($this->graph->expand($classes) as $class) {
            foreach ($map[$class] ?? [] as $needle) {
                if (is_string($needle) && str_starts_with($needle, '/')) {
                    $uris[] = $needle;
                }
            }
        }

        return array_values(array_unique($uris));
    }

    /** /users/{id}, /users/{user?} and /users/{userId} are the same route shape. */
    private static function shape(string $path): string
    {
        $shape = preg_replace('/\{[^}]+\}/', '{}', '/'.trim($path, '/')) ?? $path;

        return $shape === '/' ? '/' : rtrim($shape, '/');
    }
}
