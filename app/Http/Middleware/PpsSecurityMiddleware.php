<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PpsSecurityMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->mustRequireHttps($request)) {
            abort(Response::HTTP_FORBIDDEN, 'HTTPS is required for PPS API access.');
        }

        if ($this->hasUnsupportedPayloadType($request)) {
            abort(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'PPS API mutations must use JSON or multipart form data.');
        }

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
        );
        $response->headers->set('Vary', $this->mergeVary($response->headers->get('Vary')));

        if ($this->isSecureRequest($request)) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function mustRequireHttps(Request $request): bool
    {
        if (! (bool) config('app.pps_require_https', ! app()->environment(['local', 'testing']))) {
            return false;
        }

        return ! $this->isSecureRequest($request);
    }

    private function isSecureRequest(Request $request): bool
    {
        return $request->isSecure() || $request->header('X-Forwarded-Proto') === 'https';
    }

    private function hasUnsupportedPayloadType(Request $request): bool
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return false;
        }

        $contentType = strtolower(trim(strtok($request->header('Content-Type', ''), ';')));

        if ($contentType === '') {
            return false;
        }

        return ! in_array($contentType, [
            'application/json',
            'multipart/form-data',
        ], true);
    }

    private function mergeVary(?string $currentVary): string
    {
        $values = collect(explode(',', (string) $currentVary))
            ->map(fn (string $value) => trim($value))
            ->filter()
            ->merge(['Origin', 'Authorization', 'Cookie'])
            ->unique()
            ->values();

        return $values->implode(', ');
    }
}
