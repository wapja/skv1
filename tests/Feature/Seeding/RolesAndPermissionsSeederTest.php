<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('seeds the expected permissions and the organisation_admin template role', function () {
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

    expect(Role::where('name', 'organisation_admin')->whereNull('team_id')->exists())->toBeTrue();
});

it('does not seed a spatie super_admin role (super_admin is a User flag bypassed by Gate::before)', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::where('name', 'super_admin')->exists())->toBeFalse();
});
