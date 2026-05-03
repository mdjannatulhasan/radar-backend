<?php

namespace App\Http\Middleware;

use App\Support\ModuleCapabilities;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PpsCapabilityMiddleware
{
    // Usage: ->middleware('pps.can:marks.read')
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $user = $request->user();
        if (!$user) abort(401);

        [$module, $action] = explode('.', $capability, 2);

        if (!ModuleCapabilities::roleHas($user->role, $module, $action)) {
            Log::warning('capability_denied', [
                'user_id'    => $user->id,
                'role'       => $user->role,
                'capability' => $capability,
                'ip'         => $request->ip(),
                'path'       => $request->path(),
            ]);
            abort(403, "Insufficient capability: {$capability}");
        }
        return $next($request);
    }
}
