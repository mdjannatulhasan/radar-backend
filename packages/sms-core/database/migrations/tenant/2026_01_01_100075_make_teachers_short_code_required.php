<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SmsCore\Support\TeacherShortCode;

/**
 * A teacher always has a short code. It is what identifies them on a routine
 * grid and on every RADAR screen too narrow for a full Bangladeshi name, so a
 * teacher without one is not a teacher with a blank field — it is a row that
 * cannot be displayed. All 159 imported CPSCS staff have one; the column was
 * only nullable because the demo seeder created a teacher without one.
 *
 * Runs at 100075, i.e. between create_teachers_tables (100070) and
 * create_subjects (100080). Laravel's Migrator flat-maps every --path in
 * config/tenancy.php and then sorts the combined list by FILENAME, so ordering
 * is by name and not by which directory a file lives in — a name that sorted
 * before 100070 would run before the table it alters exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->backfill();

        Schema::table('teachers', function (Blueprint $table): void {
            $table->string('short_code', 10)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table): void {
            $table->string('short_code', 10)->nullable()->change();
        });
    }

    /**
     * Give every teacher missing a short code one derived from their name.
     *
     * De-duplicated per school, because the constraint is unique on
     * (school_id, short_code) — two "Md. Abdul Karim"s in the same school get
     * AK and AK2, while the same initials in a different school do not clash.
     */
    private function backfill(): void
    {
        $schoolIds = DB::table('teachers')
            ->whereNull('short_code')
            ->orWhere('short_code', '')
            ->distinct()
            ->pluck('school_id');

        foreach ($schoolIds as $schoolId) {
            $taken = DB::table('teachers')
                ->where('school_id', $schoolId)
                ->whereNotNull('short_code')
                ->where('short_code', '!=', '')
                ->pluck('short_code')
                ->all();

            $blanks = DB::table('teachers')
                ->where('school_id', $schoolId)
                ->where(fn ($q) => $q->whereNull('short_code')->orWhere('short_code', ''))
                ->orderBy('id')
                ->get(['id', 'full_name']);

            foreach ($blanks as $teacher) {
                $code = TeacherShortCode::unique((string) $teacher->full_name, $taken);
                $taken[] = $code;

                DB::table('teachers')->where('id', $teacher->id)->update(['short_code' => $code]);
            }
        }
    }
};
