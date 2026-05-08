<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HealthCheckAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedKey = config('services.health_check.key');
        $bearer = $request->bearerToken();

        if ($expectedKey && $bearer !== null && hash_equals((string) $expectedKey, $bearer)) {
            return $next($request);
        }

        if ($bearer !== null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (! $user->can('system.health') && ! $user->isSuperAdmin()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
