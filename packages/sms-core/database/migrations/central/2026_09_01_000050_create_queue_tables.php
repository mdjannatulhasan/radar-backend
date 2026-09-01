<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Queue tables, central rather than per-tenant.
 *
 * RADAR's original 0001_01_01_000002_create_jobs_table.php was deleted in the
 * Phase 4 cutover and nothing replaced it, leaving QUEUE_CONNECTION=database
 * pointing at tables a fresh install would never create. They belong in the
 * central schema: a job's payload carries its own tenant, and stancl's
 * QueueTenancyBootstrapper re-initialises tenancy per job, so one shared queue
 * serves every tenant.
 */
return new class extends Migration
{
    public function getConnection(): string
    {
        return 'central';
    }

    public function up(): void
    {
        $schema = Schema::connection('central');

        if (! $schema->hasTable('jobs')) {
            $schema->create('jobs', function (Blueprint $table): void {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (! $schema->hasTable('job_batches')) {
            $schema->create('job_batches', function (Blueprint $table): void {
                $table->string('id')->primary();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->longText('failed_job_ids');
                $table->mediumText('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
            });
        }

        if (! $schema->hasTable('failed_jobs')) {
            $schema->create('failed_jobs', function (Blueprint $table): void {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('failed_jobs');
        Schema::connection('central')->dropIfExists('job_batches');
        Schema::connection('central')->dropIfExists('jobs');
    }
};
