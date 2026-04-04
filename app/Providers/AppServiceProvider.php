<?php

namespace App\Providers;

use App\Models\Student;
use App\Policies\StudentPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Student::class, StudentPolicy::class);

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
