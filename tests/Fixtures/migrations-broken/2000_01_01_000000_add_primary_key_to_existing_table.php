<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SQLite cannot add a primary key column to an existing table, so a boot migration stops here. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broken', function (Blueprint $table): void {
            $table->string('name');
        });

        Schema::table('broken', function (Blueprint $table): void {
            $table->id()->first();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broken');
    }
};
