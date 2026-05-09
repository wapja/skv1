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

            // Template roles. Each tenant gets per-team copies via OrganisationObserver.
            $orgAdmin = Role::firstOrCreate(
                ['name' => 'organisation_admin', 'guard_name' => 'web', 'team_id' => null]
            );
            $orgAdmin->syncPermissions([
                'users.view', 'users.create', 'users.update', 'users.delete',
                'users.impersonate',
                'roles.view', 'roles.manage',
                'organisations.view',
                'invitations.send', 'invitations.cancel',
                'activity.view',
            ]);

            $superAdmin = Role::firstOrCreate(
                ['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => null]
            );
            $superAdmin->syncPermissions(Permission::all());

            Role::firstOrCreate(
                ['name' => 'test1', 'guard_name' => 'web', 'team_id' => null]
            );

            Role::firstOrCreate(
                ['name' => 'test2', 'guard_name' => 'web', 'team_id' => null]
            );
        });
    }
}
