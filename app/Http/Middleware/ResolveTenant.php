<?php

namespace App\Http\Middleware;

use App\Models\Organisation;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $apex = config('app.apex_domain');
        $admin = config('app.admin_host');

        if ($host === $apex || $host === $admin) {
            return $next($request);
        }

        $suffix = '.'.$apex;
        if (! str_ends_with($host, $suffix)) {
            abort(404);
        }

        $slug = substr($host, 0, -strlen($suffix));
        $organisation = Organisation::where('slug', $slug)->first();

        if (! $organisation) {
            abort(404);
        }

        app()->instance('currentOrganisation', $organisation);
        app(PermissionRegistrar::class)->setPermissionsTeamId($organisation->id);

        return $next($request);
    }
}
