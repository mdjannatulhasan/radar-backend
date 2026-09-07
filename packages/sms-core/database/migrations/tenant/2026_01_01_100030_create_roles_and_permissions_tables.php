<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-layer RBAC, taken from both products:
 *
 *   roles.enabled_features (otoroutine)  — which product MENUS a role sees.
 *                                          Feature ids are namespaced by
 *                                          product: "radar.marks.view",
 *                                          "routine.proxy.assign".
 *   role_permissions       (RADAR)       — which ACTIONS a role may perform
 *                                          within those menus. RADAR already
 *                                          has an admin UI driving this grid.
 *
 * Above both sits central tenant_products, which decides whether the school
 * has bought the product at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 50);
            $table->boolean('is_admin')->default(false);
            $table->json('enabled_features')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'slug']);
        });

        Schema::create('permission_modules', function (Blueprint $table): void {
            $table->id();
            $table->string('product', 40)->default('radar');
            $table->string('name', 50);
            $table->string('label', 100);
            $table->unsignedTinyInteger('sort_order')->default(0);

            $table->unique(['product', 'name']);
        });

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('role', 50);
            $table->string('module', 50);
            $table->string('action', 50);
            $table->boolean('granted')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['role', 'module', 'action']);
            $table->index(['role', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permission_modules');
        Schema::dropIfExists('roles');
    }
};
