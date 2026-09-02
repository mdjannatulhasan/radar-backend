<?php

namespace App\Providers;

use App\Policies\StudentPolicy;
use App\Support\PpsPermissions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use SmsCore\Models\Student;
use SmsCore\Models\User;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\Pps\ComputedScoreService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(\SmsCore\SmsCoreServiceProvider::centralMigrationPath());

        Gate::policy(Student::class, StudentPolicy::class);

        // sms-core's User has no opinion on RADAR's permission vocabulary; it
        // asks the product. Without this, every user has zero permissions.
        User::$permissionResolver = static fn (User $user): array => PpsPermissions::forRole($user->role);

        RateLimiter::for('pps-api', function (Request $request): array {
            $identity = $request->user()?->getAuthIdentifier() ?: $request->ip();
            $perMinute = $request->user()?->hasAnyRole(['principal', 'admin']) ? 180 : 90;

            return [
                Limit::perMinute($perMinute)->by('pps-api:'.$identity),
            ];
        });

        RateLimiter::for('pps-auth', function (Request $request): array {
            $email = strtolower(trim($request->input('email', 'guest')));

            return [
                Limit::perMinute(5)->by('pps-auth:'.$request->ip()),
                Limit::perMinute(10)->by('pps-auth-email:'.$email),
            ];
        });

        // Authenticated platform console traffic. It gets its own limiter
        // because pps-api keys on $request->user(), which resolves the default
        // guard and is therefore always null for an admin — every platform
        // admin behind one office IP would have shared a single pps-api bucket
        // with that office's school traffic.
        RateLimiter::for('platform-api', function (Request $request): array {
            $identity = $request->user('admin')?->getAuthIdentifier() ?: $request->ip();

            return [Limit::perMinute(120)->by('platform-api:'.$identity)];
        });

        // Platform super-admin login. Same shape and same numbers as pps-auth,
        // on its own key space so the two cannot exhaust each other: a school
        // full of people fat-fingering their password must not lock the
        // operator out of the console, and vice versa.
        RateLimiter::for('platform-auth', function (Request $request): array {
            $email = strtolower(trim($request->input('email', 'guest')));

            return [
                Limit::perMinute(5)->by('platform-auth:'.$request->ip()),
                Limit::perMinute(10)->by('platform-auth-email:'.$email),
            ];
        });
    }
}
