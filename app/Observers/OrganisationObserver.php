<?php

namespace App\Observers;

use App\Contracts\TenantOwned;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class OrganisationObserver
{
    /**
     * Models known to implement TenantOwned. Add new TenantOwned models here
     * so the cascade soft-delete reaches them. The observer guards each one
     * with a class_implements() check, so an accidental wrong entry is ignored.
     */
    private const TENANT_OWNED_MODELS = [
        User::class,
    ];

    /**
     * Roles that are materialised per-tenant. Templates live at team_id=null
     * (seeded by RolesAndPermissionsSeeder); per-tenant copies are created
     * here when a new organisation comes into existence.
     */
    private const PROPAGATED_ROLES = [
        'organisation_admin',
        'super_admin',
        'test1',
        'test2',
    ];

    public function created(Organisation $organisation): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        try {
            DB::transaction(function () use ($organisation, $registrar) {
                $registrar->setPermissionsTeamId($organisation->id);

                foreach (self::PROPAGATED_ROLES as $name) {
                    $template = Role::where('name', $name)->whereNull('team_id')->first();
                    if (! $template) {
                        continue;
                    }

                    $tenantRole = Role::firstOrCreate([
                        'name' => $name,
                        'guard_name' => 'web',
                        'team_id' => $organisation->id,
                    ]);

                    $tenantRole->syncPermissions($template->permissions);
                }

                // Propagate super_admin assignment to existing super-admins.
                // The HasRoles `roles` relationship is team-scoped, so we query
                // the pivot table directly (bypassing scope) to find users with
                // any super_admin pivot row in any team.
                $superAdminUserIds = DB::table('model_has_roles')
                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->where('roles.name', 'super_admin')
                    ->where('model_has_roles.model_type', (new User)->getMorphClass())
                    ->pluck('model_has_roles.model_id')
                    ->unique();

                User::query()
                    ->withoutGlobalScopes()
                    ->whereIn('id', $superAdminUserIds)
                    ->each(function (User $user) {
                        if (! $user->hasRole('super_admin')) {
                            $user->assignRole('super_admin');
                        }
                    });
            });
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }
    }

    public function deleted(Organisation $organisation): void
    {
        if ($organisation->isForceDeleting()) {
            return;
        }

        $timestamp = $organisation->deleted_at;

        DB::transaction(function () use ($organisation, $timestamp) {
            foreach (self::TENANT_OWNED_MODELS as $modelClass) {
                if (! in_array(TenantOwned::class, class_implements($modelClass), true)) {
                    continue;
                }

                $modelClass::query()
                    ->withoutGlobalScopes()
                    ->where('organisation_id', $organisation->id)
                    ->whereNull('deleted_at')
                    ->update([
                        'deleted_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
            }
        });
    }

    public function restoring(Organisation $organisation): void
    {
        $timestamp = $organisation->deleted_at;

        DB::transaction(function () use ($organisation, $timestamp) {
            foreach (self::TENANT_OWNED_MODELS as $modelClass) {
                if (! in_array(TenantOwned::class, class_implements($modelClass), true)) {
                    continue;
                }

                $modelClass::query()
                    ->withoutGlobalScopes()
                    ->where('organisation_id', $organisation->id)
                    ->where('deleted_at', $timestamp)
                    ->update([
                        'deleted_at' => null,
                    ]);
            }
        });
    }
}
