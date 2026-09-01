<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central registry of tenants. `slug` is the subdomain label and the schema
 * suffix: slug `cpscs` -> https://cpscs.radar.app -> schema `tenant_cpscs`.
 * Everything not listed in Tenant::getCustomColumns() is virtualised into
 * the JSON `data` column by stancl.
 */
return new class extends Migration
{
    public function getConnection(): string
    {
        return 'central';
    }

    public function up(): void
    {
        Schema::connection('central')->create('tenants', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('provisioning_status')->default('pending');
            $table->timestamp('migrated_at')->nullable();
            $table->timestamps();
            $table->json('data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('tenants');
    }
};
