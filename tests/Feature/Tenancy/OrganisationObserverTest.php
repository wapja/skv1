<?php

use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

describe('OrganisationObserver::created', function () {
    it('creates per-tenant copies of every role template when an organisation is created', function () {
        $org = Organisation::factory()->create(['slug' => 'fresh-tenant']);

        foreach (['organisation_admin', 'super_admin', 'test1', 'test2'] as $name) {
            $role = Role::where('name', $name)->where('team_id', $org->id)->first();
            expect($role)->not->toBeNull("expected per-tenant copy of {$name} for org {$org->id}");
        }
    });

    it('copies template permissions onto the per-tenant super_admin role', function () {
        $org = Organisation::factory()->create(['slug' => 'perm-check']);

        $tenantSuperAdmin = Role::where('name', 'super_admin')
            ->where('team_id', $org->id)
            ->first();

        expect($tenantSuperAdmin->permissions)->not->toBeEmpty()
            ->and($tenantSuperAdmin->permissions->pluck('name'))->toContain('users.delete', 'roles.manage');
    });

    it('keeps test1 and test2 permissionless on per-tenant copies', function () {
        $org = Organisation::factory()->create(['slug' => 'no-perms']);

        $test1 = Role::where('name', 'test1')->where('team_id', $org->id)->first();
        $test2 = Role::where('name', 'test2')->where('team_id', $org->id)->first();

        expect($test1->permissions)->toBeEmpty()
            ->and($test2->permissions)->toBeEmpty();
    });

    it('propagates super_admin role assignment to existing super-admins when a new org is created', function () {
        $org1 = Organisation::factory()->create(['slug' => 'org-one']);

        $superAdmin = User::factory()->create(['organisation_id' => null]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($org1->id);
        $superAdmin->assignRole('super_admin');

        $org2 = Organisation::factory()->create(['slug' => 'org-two']);

        app(PermissionRegistrar::class)->setPermissionsTeamId($org2->id);
        expect($superAdmin->fresh()->hasRole('super_admin'))->toBeTrue();
    });

    it('propagates super_admin role to all existing super-admins, not just one', function () {
        $org1 = Organisation::factory()->create(['slug' => 'multi-org-one']);

        $superA = User::factory()->create(['organisation_id' => null]);
        $superB = User::factory()->create(['organisation_id' => null]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($org1->id);
        $superA->assignRole('super_admin');
        $superB->assignRole('super_admin');

        $org2 = Organisation::factory()->create(['slug' => 'multi-org-two']);

        app(PermissionRegistrar::class)->setPermissionsTeamId($org2->id);
        expect($superA->fresh()->hasRole('super_admin'))->toBeTrue('expected superA in org2')
            ->and($superB->fresh()->hasRole('super_admin'))->toBeTrue('expected superB in org2');
    });
});
