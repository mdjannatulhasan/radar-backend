<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use SmsCore\Models\Admin;

/**
 * Seeds the CENTRAL schema (public): platform super admins.
 *
 * Deliberately separate from DatabaseSeeder, which is the *tenant* seeder —
 * config/tenancy.php points `tenants:seed` at that class, and it throws unless
 * a school has already been imported. This one touches nothing inside a tenant
 * and must run on a bare install, before the first school exists, because
 * provisioning that first school is exactly what the admin it creates is for.
 *
 *   php artisan db:seed --class=CentralDatabaseSeeder
 */
class CentralDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('PLATFORM_ADMIN_PASSWORD', 'PpsDemo2026!');

        // updateOrCreate so re-running is safe and does not reset a password
        // that was deliberately changed... except it would, so key on email and
        // only fill the password when the row is new.
        $admin = Admin::firstOrNew(['email' => 'platform@radar.local']);

        if (! $admin->exists) {
            $admin->password = $password;
        }

        $admin->name = 'Platform Super Admin';
        $admin->save();

        $this->command?->info(
            $admin->wasRecentlyCreated
                ? 'Created platform admin platform@radar.local'
                : 'Platform admin platform@radar.local already exists (password left alone).'
        );
    }
}
