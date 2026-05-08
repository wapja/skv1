<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'users.view',
            'users.create',
            'users.update',
            'users.delete',
            'users.impersonate',
            'roles.view',
            'roles.manage',
            'organisations.view',
            'organisations.manage',
            'invitations.send',
            'invitations.cancel',
            'system.health',
            'activity.view',
            'backup.manage',
        ];

        DB::transaction(function () use ($permissions) {
            foreach ($permissions as $name) {
                Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            }

            // Template role for organisation admins. Each tenant gets its own
            // copy at organisation creation time (team_id = organisations.id).
            // Super-admin is *not* a spatie role — it's a User flag (is_super_admin)
            // bypassed by Gate::before. See AppServiceProvider::boot().
            $template = Role::firstOrCreate(
                ['name' => 'organisation_admin', 'guard_name' => 'web', 'team_id' => null]
            );

            $template->syncPermissions([
                'users.view', 'users.create', 'users.update', 'users.delete',
                'users.impersonate',
                'roles.view', 'roles.manage',
                'organisations.view',
                'invitations.send', 'invitations.cancel',
                'activity.view',
            ]);
        });
    }
}
