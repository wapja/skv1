<?php

namespace App\Http\Middleware;

use App\Models\Organisation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
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
            $this->scopeForApexUser();

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

    /**
     * On the apex host there is no tenant context. For super-admins we anchor
     * permission checks to the lowest-id organisation so Spatie's team-scoped
     * role lookups can find their `super_admin` assignment. Super-admins have
     * the role in every org, so the choice is reproducible and arbitrary.
     */
    protected function scopeForApexUser(): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }

        // Direct pivot query — bypasses Spatie's team-scoped `roles()` relation
        // so we can see the role assignment under any team_id. At this point
        // no team-id has been set yet (or it was set to a stale value by an
        // earlier middleware), so going through `$user->roles()` would
        // incorrectly filter out the assignment.
        $superAdminRoleId = Role::where('name', 'super_admin')->value('id');
        if (! $superAdminRoleId) {
            return;
        }

        $isSuperAdmin = DB::table('model_has_roles')
            ->where('role_id', $superAdminRoleId)
            ->where('model_type', $user->getMorphClass())
            ->where('model_id', $user->getKey())
            ->exists();
        if (! $isSuperAdmin) {
            return;
        }

        $firstOrg = Organisation::orderBy('id')->first();
        if ($firstOrg) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($firstOrg->id);
        }
    }
}
