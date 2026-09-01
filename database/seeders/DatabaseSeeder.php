<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use SmsCore\Models\School;

/**
 * Seeds RADAR's demo data on top of a tenant that has already been imported.
 *
 * Order matters. RolePermissionSeeder first, because a tenant whose
 * role_permissions table is empty grants nobody anything and a successful login
 * lands on an empty app. PpsAdministrationSeeder next, to resolve the real
 * school and pick the demo sections everything downstream shares. Then exams,
 * because a mark is keyed by (exam component, student, subject) and every
 * component a student can be scored against must exist before the cohort is
 * generated.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // The taxonomy is imported, never seeded. Running this against an empty
        // tenant used to silently invent a second "PPS Demo School" alongside
        // the real one; failing here instead is the whole point.
        if (! School::exists()) {
            throw new \RuntimeException(
                'No school in this tenant. Run: php artisan tenants:run sms:import:otoroutine --tenants=<slug>'
            );
        }

        PpsAdministrationSeeder::flushCaches();

        $this->call([
            RolePermissionSeeder::class,
            PpsGradeConfigSeeder::class,
            PpsAdministrationSeeder::class,
            PpsExamSeeder::class,
            PpsTeacherAssignmentSeeder::class,
            PpsDemoSeeder::class,
        ]);
    }
}
