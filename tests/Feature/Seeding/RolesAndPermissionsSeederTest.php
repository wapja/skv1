<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('seeds the expected permissions and global roles', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $expectedPermissions = [
        'users.view', 'users.create', 'users.update', 'users.delete', 'users.impersonate',
        'roles.view', 'roles.manage',
        'organisations.view', 'organisations.manage',
        'invitations.send', 'invitations.cancel',
        'system.health',
        'activity.view',
        'backup.manage',
    ];

    foreach ($expectedPermissions as $permName) {
        expect(Permission::where('name', $permName)->where('guard_name', 'web')->exists())
            ->toBeTrue("permission {$permName} not seeded");
    }

    expect(Permission::count())->toBe(count($expectedPermissions));

    expect(Role::where('name', 'super_admin')->whereNull('team_id')->exists())->toBeTrue();
    expect(Role::where('name', 'organisation_admin')->whereNull('team_id')->exists())->toBeTrue();
});

it('grants all permissions to super_admin', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $superAdmin = Role::where('name', 'super_admin')->whereNull('team_id')->first();

    expect($superAdmin->permissions()->count())->toBe(Permission::count());
});
