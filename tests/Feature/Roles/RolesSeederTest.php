<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

describe('RolesAndPermissionsSeeder', function () {
    it('creates the super_admin template role with every permission', function () {
        $role = Role::where('name', 'super_admin')->whereNull('team_id')->first();

        expect($role)->not->toBeNull();
        expect($role->permissions->pluck('name')->sort()->values()->all())
            ->toBe(Permission::pluck('name')->sort()->values()->all());
    });

    it('creates the test1 template role with no permissions', function () {
        $role = Role::where('name', 'test1')->whereNull('team_id')->first();

        expect($role)->not->toBeNull()
            ->and($role->permissions)->toBeEmpty();
    });

    it('creates the test2 template role with no permissions', function () {
        $role = Role::where('name', 'test2')->whereNull('team_id')->first();

        expect($role)->not->toBeNull()
            ->and($role->permissions)->toBeEmpty();
    });

    it('keeps the organisation_admin template intact', function () {
        $role = Role::where('name', 'organisation_admin')->whereNull('team_id')->first();

        expect($role)->not->toBeNull()
            ->and($role->permissions->pluck('name'))->toContain('invitations.send', 'users.view', 'roles.manage');
    });
});
