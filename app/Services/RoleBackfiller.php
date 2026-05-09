<?php

namespace App\Services;

use App\Models\Organisation;
use App\Observers\OrganisationObserver;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class RoleBackfiller
{
    /**
     * Names of roles materialised per-organisation by OrganisationObserver.
     * Kept in sync manually with OrganisationObserver::PROPAGATED_ROLES.
     */
    private const PROPAGATED_ROLES = [
        'organisation_admin',
        'super_admin',
        'test1',
        'test2',
    ];

    public function backfillExistingOrganisations(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();
        $observer = new OrganisationObserver;

        try {
            DB::transaction(function () use ($observer) {
                foreach (Organisation::all() as $org) {
                    $observer->created($org);

                    $this->repointPivotsForOrg($org);
                }
            });
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
            $registrar->forgetCachedPermissions();
        }
    }

    private function repointPivotsForOrg(Organisation $org): void
    {
        foreach (self::PROPAGATED_ROLES as $name) {
            $template = DB::table('roles')
                ->where('name', $name)
                ->whereNull('team_id')
                ->where('guard_name', 'web')
                ->whereNull('deleted_at')
                ->first();

            $perOrg = DB::table('roles')
                ->where('name', $name)
                ->where('team_id', $org->id)
                ->where('guard_name', 'web')
                ->whereNull('deleted_at')
                ->first();

            if (! $template || ! $perOrg) {
                continue;
            }

            DB::table('model_has_roles')
                ->where('role_id', $template->id)
                ->where('team_id', $org->id)
                ->update(['role_id' => $perOrg->id]);
        }
    }
}
