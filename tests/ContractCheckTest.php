<?php

declare(strict_types=1);

namespace Probe\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Probe\OpenApi\ContractCheck;
use Probe\Runner\DatabaseGuard;
use Probe\Runner\SnippetRunner;
use Probe\Selective\DependencyGraph;
use Probe\Selective\RouteMap;

final class ContractCheckTest extends TestCase
{
    private string $root;

    private DatabaseGuard $database;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/probe-contract-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/app/Http/Controllers', 0775, true);
        file_put_contents(
            $this->root.'/app/Http/Controllers/ContractFixtureController.php',
            "<?php\nnamespace Probe\\Tests;\nfinal class ContractFixtureController {}\n",
        );

        Route::get('/api/users/{id}', [ContractFixtureController::class, 'show']);
        Route::get('/api/users', static fn (): array => ['data' => [['id' => 1, 'email' => 'a@b.c'], ['id' => 2, 'email' => null]]]);
        Route::get('/api/broken', static function (): never {
            throw new \RuntimeException('boom');
        });
        Route::get('/api/html', static fn (): string => '<html>hi</html>');
        Route::post('/api/notes', static function (): array {
            DB::table('notes')->insert(['body' => (string) request()->json('body')]);

            return ['data' => ['id' => (int) DB::table('notes')->max('id'), 'body' => (string) request()->json('body')]];
        });

        // The guard moves the default connection to its own in-memory database; the table goes there.
        $this->database = new DatabaseGuard;
        $this->database->boot($this->app);
        Schema::create('notes', static function ($table): void {
            $table->increments('id');
            $table->string('body');
        });
    }

    public function test_every_operation_passes_when_the_app_matches_the_spec(): void
    {
        $this->spec(['GET /api/users/{id}' => self::userResponse(), 'POST /api/notes' => self::noteOperation()]);

        $result = $this->check()->run([]);

        $this->assertSame('passed', $result->status, $result->toJson());
        $this->assertSame(['operations' => 2, 'checked' => 2, 'failed' => 0, 'selection' => 'all', 'spec' => 'openapi.json'], $result->value);
    }

    public function test_a_null_in_a_required_string_is_one_failure_with_its_pointer(): void
    {
        $this->spec(['GET /api/users' => self::listResponse()]);

        $result = $this->check()->run([]);

        $this->assertSame('failed', $result->status, $result->toJson());
        $this->assertCount(1, $result->failures);
        $this->assertSame('GET /api/users 200: $.data[1].email: expected string, got null', $result->failures[0]->message);
        $this->assertSame('contract', $result->failures[0]->type);
        $this->assertSame('openapi.json', $result->failures[0]->file);
    }

    public function test_a_wrong_status_and_a_non_json_body_are_failures(): void
    {
        $this->spec(['GET /api/broken' => self::userResponse(), 'GET /api/html' => self::userResponse()]);

        $result = $this->check()->run([]);

        $this->assertSame('failed', $result->status, $result->toJson());
        $this->assertStringStartsWith('GET /api/broken: expected 200, got 500', $result->failures[0]->message);
        $this->assertStringStartsWith('GET /api/html 200: response is not JSON', $result->failures[1]->message);
    }

    public function test_operations_without_examples_are_skipped_and_an_all_skipped_run_is_an_error(): void
    {
        $noExample = self::userResponse();
        unset($noExample['parameters'][0]['example']);
        $this->spec(['GET /api/users/{id}' => $noExample, 'GET /api/users' => self::listResponse()]);

        $result = $this->check()->run([]);

        $this->assertSame('failed', $result->status, $result->toJson());
        $this->assertSame(1, $result->value['checked']);
        $this->assertSame([['operation' => 'GET /api/users/{id}', 'reason' => 'no example for path parameter id']], $result->value['skipped']);

        $this->spec(['GET /api/users/{id}' => $noExample]);
        $none = $this->check()->run([]);
        $this->assertSame('error', $none->status);
        $this->assertSame('examples', $none->failures[0]->id);
    }

    public function test_routes_and_changed_paths_select_operations(): void
    {
        $this->spec(['GET /api/users/{id}' => self::userResponse(), 'GET /api/users' => self::listResponse()]);

        $given = $this->check()->run(['routes' => ['GET /api/users/{userId}']]);
        $this->assertSame('passed', $given->status, $given->toJson());
        $this->assertSame('given', $given->value['selection']);
        $this->assertSame(1, $given->value['checked']);

        $affected = $this->check()->run(['paths' => ['app/Http/Controllers/ContractFixtureController.php']]);
        $this->assertSame('passed', $affected->status, $affected->toJson());
        $this->assertSame('affected', $affected->value['selection']);
        $this->assertSame(1, $affected->value['checked'], 'only the controller\'s documented route runs');

        $nothing = $this->check()->run(['routes' => 'DELETE /nowhere']);
        $this->assertSame('error', $nothing->status);
        $this->assertSame('routes', $nothing->failures[0]->id);
    }

    public function test_a_yaml_spec_loads_and_a_missing_spec_lists_the_places_searched(): void
    {
        file_put_contents($this->root.'/openapi.yaml', "openapi: 3.0.0\npaths:\n  /api/users/{id}:\n    get:\n      parameters:\n        - name: id\n          in: path\n          example: 1\n      responses:\n        '200':\n          content:\n            application/json:\n              schema:\n                type: object\n                required: [id]\n                properties:\n                  id: {type: integer}\n");

        $yaml = $this->check()->run([]);
        $this->assertSame('passed', $yaml->status, $yaml->toJson());
        $this->assertSame('openapi.yaml', $yaml->value['spec']);

        unlink($this->root.'/openapi.yaml');
        $missing = $this->check()->run([]);
        $this->assertSame('error', $missing->status);
        $this->assertSame('spec', $missing->failures[0]->id);
        $this->assertStringContainsString('docs/openapi.yaml', $missing->failures[0]->message);
    }

    public function test_a_write_inside_the_handler_rolls_back_and_the_body_file_is_removed(): void
    {
        $this->spec(['POST /api/notes' => self::noteOperation()]);

        $result = $this->check()->run([]);

        $this->assertSame('passed', $result->status, $result->toJson());
        $this->assertSame(0, DB::table('notes')->count(), 'the request ran inside the probe transaction');
        $this->assertSame([], glob($this->root.'/home/contract-*') ?: []);
    }

    private function check(): ContractCheck
    {
        if (! is_dir($this->root.'/home')) {
            mkdir($this->root.'/home', 0775, true);
        }

        return new ContractCheck(
            $this->root,
            new SnippetRunner($this->database, 1024, 5000),
            new DependencyGraph($this->root),
            fn (): array => RouteMap::fromRouter($this->app['router']),
            $this->root.'/home',
            ['limit' => 10, 'timeout_ms' => 60000],
        );
    }

    /** @param  array<string, array<string, mixed>>  $operations  "METHOD /path" to operation object */
    private function spec(array $operations): void
    {
        $paths = [];
        foreach ($operations as $key => $operation) {
            [$method, $path] = explode(' ', $key, 2);
            $paths[$path][strtolower($method)] = $operation;
        }

        file_put_contents($this->root.'/openapi.json', json_encode([
            'openapi' => '3.0.0',
            'paths' => $paths,
            'components' => ['schemas' => ['User' => [
                'type' => 'object',
                'required' => ['id', 'email'],
                'properties' => ['id' => ['type' => 'integer'], 'email' => ['type' => 'string']],
            ]]],
        ]));
    }

    /** @return array<string, mixed> */
    private static function userResponse(): array
    {
        return [
            'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'example' => 7]],
            'responses' => ['200' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/User']]]]],
        ];
    }

    /** @return array<string, mixed> */
    private static function listResponse(): array
    {
        return [
            'responses' => ['200' => ['content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/User']]],
            ]]]]],
        ];
    }

    /** @return array<string, mixed> */
    private static function noteOperation(): array
    {
        return [
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['example' => ['body' => 'hello']]]],
            'responses' => ['200' => ['content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => ['data' => ['type' => 'object', 'required' => ['id', 'body'], 'properties' => ['id' => ['type' => 'integer'], 'body' => ['type' => 'string']]]],
            ]]]]],
        ];
    }
}

final class ContractFixtureController
{
    /** @return array<string, mixed> */
    public function show(int $id): array
    {
        return ['id' => $id, 'email' => 'user'.$id.'@example.test'];
    }
}
