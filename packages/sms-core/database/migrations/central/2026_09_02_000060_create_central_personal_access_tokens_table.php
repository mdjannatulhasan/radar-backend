<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sanctum tokens for platform super admins, in the CENTRAL schema.
 *
 * There is a table of the same name in every tenant schema (see the tenant
 * migration create_users_and_auth_tables). That is the point, not a mistake:
 * Sanctum's PersonalAccessToken rides the default connection, whose
 * search_path tenancy swaps per request, so "which personal_access_tokens"
 * is decided by the host the request arrived on.
 *
 *   radar.test        -> search_path public       -> admin tokens
 *   cpscs.radar.test  -> search_path tenant_cpscs -> that school's tokens
 *
 * Neither lookup can see the other's rows, which is what keeps a platform
 * token from authenticating against a school's API and vice versa. Without
 * this table the central half of that arrangement has nowhere to store
 * anything and platform login dies on a missing relation — which is exactly
 * what happened: the development database only worked because it still
 * carried a stray pre-tenancy personal_access_tokens in public, and the test
 * database, built purely from migrations, did not.
 */
return new class extends Migration
{
    public function getConnection(): string
    {
        return 'central';
    }

    public function up(): void
    {
        if (Schema::connection('central')->hasTable('personal_access_tokens')) {
            return;
        }

        Schema::connection('central')->create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('personal_access_tokens');
    }
};
