# Demo Seed — Production Deployment Guide

This document covers every step required to deploy the RADAR demo dataset on a production or staging server.  
Run through it top-to-bottom. Each section is idempotent where noted.

---

## What the seed creates

| Category | Count | Notes |
|---|---|---|
| Students (total) | 158 | 8 featured + 150 bulk |
| Classes | 5 (6–10) | Each split into Section A and B |
| Students per class/section | 15–16 | Roll 1 = top, Roll 15 = at-risk |
| Guardian profiles | 158 | Profession, category, time availability, quadrant |
| Economically vulnerable | ~27 | Auto-flagged from profession + roll |
| Family statuses | 8 types | stable, single parent (m/f), deceased, abroad, separated, divorced, remarried |
| Performance snapshots | 6 months × 158 | Deterministic — reproducible on re-seed |
| Alerts | Varies | urgent / warning / watch for at-risk students |
| Counseling sessions | Varies | Auto-created for urgent/warning students |
| Assessments | 3 months × 4 subjects × 158 | Per-student, consistent with roll |
| Attendance records | 22 days × 158 | Patterns match seed type |
| Exam definitions | 25 | Mid-term per class × subject, via ExamScope |

**Default password for all demo accounts:** `PpsDemo2026!`

---

## Prerequisites

- PHP 8.2+, Composer installed
- PostgreSQL 14+ running and accessible
- `.env` configured with correct `DB_*` values
- App dependencies installed (`composer install --no-dev`)

---

## Step 1 — SSH into the server

```bash
ssh deploy@your-server-ip
cd /var/www/pps/backend   # adjust path to actual deployment root
```

---

## Step 2 — Pull latest code

```bash
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan config:cache
php artisan route:cache
```

---

## Step 3 — Run migrations

> **CAUTION:** `migrate:fresh` drops all existing data. Only do this on a fresh demo/staging instance.  
> On a live production database with real data, run `migrate` (without `--fresh`) instead.

**Fresh demo instance (safe to wipe):**
```bash
php artisan migrate:fresh
```

**Live server with existing data (non-destructive):**
```bash
php artisan migrate
```

---

## Step 4 — Seed the demo data

```bash
php artisan db:seed --class=PpsDemoSeeder
```

Expected output:
```
Database\Seeders\PpsAdministrationSeeder ........... DONE
Database\Seeders\PpsDemoSeeder ..................... DONE
```

If you see `Unique constraint violation`, the data was already seeded. Run `migrate:fresh` first (Step 3, fresh path) and then re-seed.

---

## Step 5 — Verify the seed

Run this tinker one-liner to confirm row counts:

```bash
php artisan tinker --execute="
echo 'Students: '.App\Models\Student::count().'\n';
echo 'Class 6A: '.App\Models\Student::where('class_name','6')->where('section','A')->count().'\n';
echo 'Eco vulnerable: '.App\Models\Student::where('economically_vulnerable',true)->count().'\n';
echo 'Roll 1 sample: '.App\Models\Student::where('roll_number',1)->where('class_name','9')->where('section','A')->value('student_quadrant').'\n';
echo 'Roll 15 sample: '.App\Models\Student::where('roll_number',15)->where('class_name','9')->where('section','A')->value('student_quadrant').'\n';
"
```

Expected output:
```
Students: 158
Class 6A: 16
Eco vulnerable: 27
Roll 1 sample: willing_able
Roll 15 sample: unwilling_unable
```

---

## Step 6 — Storage and permissions (if needed)

```bash
php artisan storage:link
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

---

## Step 7 — Restart queue and cache (if applicable)

```bash
php artisan queue:restart
php artisan cache:clear
```

---

## Rollback / Re-seed

To wipe all data and start fresh:

```bash
php artisan migrate:fresh && php artisan db:seed --class=PpsDemoSeeder
```

---

## Demo login accounts

| Role | Email | Password |
|---|---|---|
| Super Admin | superadmin@pps.local | PpsDemo2026! |
| Admin | admin@pps.local | PpsDemo2026! |
| Principal | principal@pps.local | PpsDemo2026! |
| Counselor | counselor@pps.local | PpsDemo2026! |
| Welfare Officer | welfare@pps.local | PpsDemo2026! |
| Teacher (Math) | teacher.math@pps.local | PpsDemo2026! |
| Teacher (English) | teacher.english@pps.local | PpsDemo2026! |
| Teacher (Science) | teacher.science@pps.local | PpsDemo2026! |
| Teacher (Bangla) | teacher.bangla@pps.local | PpsDemo2026! |
| Guardian (featured) | guardian.rafi@pps.local | PpsDemo2026! |

Guardian accounts follow the pattern `guardian.bulk{NNN}@pps.local` for bulk students.

---

## What changed vs. previous seed

| Before | After |
|---|---|
| 5 students per class/section | 15 students per class/section |
| Roll number had no relation to performance | Roll 1 = top (strong/good), Roll 15 = at-risk (urgent) |
| No guardian profession data | Full profiling: profession, category, time_availability |
| No student quadrant | willingness_score, ability_score, student_quadrant populated |
| No economically_vulnerable flag | Auto-flagged from profession + roll |
| Generic family status | 8 realistic types including deceased, separated, divorced |
| ExamDefinition used deleted columns | Fixed: ExamScope used correctly (post-migration schema) |
