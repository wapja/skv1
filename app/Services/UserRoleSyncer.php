<?php

namespace App\Services;

use App\Models\Organisation;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

class UserRoleSyncer
{
    /**
     * Set the user's role state to exactly $selectedRoles, scoped to
     * $primaryOrganisationId. Regular roles (everything except super_admin)
     * are synced within the primary org's team scope. The `super_admin`
     * role is treated as cross-org binary state — when present, it gets
     * assigned in every organisation; when absent, it gets removed
     * everywhere.
     *
     * @param  array<int,string>  $selectedRoles  internal role names
     */
    public function sync(User $user, array $selectedRoles, int $primaryOrganisationId): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        try {
            $regular = array_values(array_diff($selectedRoles, ['super_admin']));
            $wantsSuperAdmin = in_array('super_admin', $selectedRoles, true);

            // Regular roles: sync inside the primary-org team scope.
            $registrar->setPermissionsTeamId($primaryOrganisationId);
            $user->syncRoles($regular);

            // super_admin: cross-org binary state.
            // Use direct relationship query with team-scoping disabled to
            // see assignments under any team_id (mirrors User::isSuperAdmin).
            $teamsEnabled = $registrar->teams;
            $registrar->teams = false;
            try {
                $hasSuperAdminAnywhere = $user->roles()
                    ->where('name', 'super_admin')
                    ->exists();
            } finally {
                $registrar->teams = $teamsEnabled;
            }

            if ($wantsSuperAdmin && ! $hasSuperAdminAnywhere) {
                foreach (Organisation::all() as $org) {
                    $registrar->setPermissionsTeamId($org->id);
                    // Drop the team-scoped roles cache so hasRole() reflects
                    // the freshly-set team_id rather than the previous scope.
                    $user->unsetRelation('roles');
                    if (! $user->hasRole('super_admin')) {
                        $user->assignRole('super_admin');
                    }
                }
            }

            if (! $wantsSuperAdmin && $hasSuperAdminAnywhere) {
                foreach (Organisation::all() as $org) {
                    $registrar->setPermissionsTeamId($org->id);
                    $user->unsetRelation('roles');
                    if ($user->hasRole('super_admin')) {
                        $user->removeRole('super_admin');
                    }
                }
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }
    }
}
