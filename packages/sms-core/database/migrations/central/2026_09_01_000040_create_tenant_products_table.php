<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which products a tenant has bought. This is the subscription layer: it gates
 * whole products (RADAR on, Autoroutine off). Per-role authorisation WITHIN a
 * product is a separate concern, handled by roles.enabled_features in the
 * tenant schema.
 */
return new class extends Migration
{
    public function getConnection(): string
    {
        return 'central';
    }

    public function up(): void
    {
        Schema::connection('central')->create('tenant_products', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('product', 40);
            $table->string('status', 20)->default('trial');
            $table->string('plan', 40)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')
                ->references('id')->on('tenants')
                ->onUpdate('cascade')->onDelete('cascade');

            $table->unique(['tenant_id', 'product']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('tenant_products');
    }
};
