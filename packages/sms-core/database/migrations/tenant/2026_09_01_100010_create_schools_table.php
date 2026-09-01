<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A school (campus) inside a tenant. Most tenants have exactly one, but the
 * column stays on every table so a group of campuses can share one tenant —
 * and so this schema stays byte-identical to otoroutine's, which is what makes
 * the eventual product merge a data copy rather than a rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique()->nullable();
            $table->text('description')->nullable();
            $table->string('logo_path')->nullable();
            $table->json('weekend_days')->nullable()
                ->comment('Day-of-week integers: 0=Sun … 6=Sat. Default Fri(5)+Sat(6)');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schools');
    }
};
