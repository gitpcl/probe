<?php

declare(strict_types=1);

namespace Probe\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Probe\Runner\DatabaseGuard;
use Probe\Runner\SnippetRunner;

final class DatabaseGuardTest extends TestCase
{
    public function test_worker_default_connection_is_sqlite_memory(): void
    {
        $guard = new DatabaseGuard;

        $this->assertSame('sqlite_memory', $guard->boot($this->app));
        $this->assertSame('probe', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.probe.database'));
    }

    public function test_probe_sees_its_write_and_then_rolls_it_back(): void
    {
        $guard = new DatabaseGuard;
        $guard->boot($this->app);

        Schema::create('notes', function ($table): void {
            $table->increments('id');
            $table->string('body');
        });

        $runner = new SnippetRunner($guard, 1024, 5000);
        $result = $runner->run('DB::table("notes")->insert(["body" => "hi"]); return DB::table("notes")->count();');

        $this->assertTrue($result->ok());
        $this->assertSame(1, $result->value);
        $this->assertSame(0, DB::table('notes')->count());
    }

    public function test_host_mode_leaves_the_configured_connection(): void
    {
        config(['probe.database' => 'host']);
        $guard = new DatabaseGuard;

        $this->assertSame('host', $guard->boot($this->app));
        $this->assertSame('sqlite', config('database.default'));
    }
}
