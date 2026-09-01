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
    }
}
