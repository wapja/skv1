<?php

use App\Http\Middleware\ResolveTenant;
use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    config(['app.apex_domain' => 'skv1.test', 'app.url' => 'https://skv1.test']);
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->orgLow = Organisation::factory()->create(['slug' => 'aaa-first']);
    $this->orgHigh = Organisation::factory()->create(['slug' => 'zzz-last']);

    $this->superAdmin = User::factory()->create(['organisation_id' => null]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->orgLow->id);
    $this->superAdmin->assignRole('super_admin');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->orgHigh->id);
    $this->superAdmin->assignRole('super_admin');
});

describe('ResolveTenant apex super-admin fallback', function () {
    it('sets setPermissionsTeamId to the lowest-id organisation for super-admins on apex', function () {
        // Reset team-id so we observe what the middleware sets.
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $this->actingAs($this->superAdmin);

        $request = Request::create('https://skv1.test/dashboard', 'GET');

        app(ResolveTenant::class)->handle($request, fn () => new Response);

        $resolved = app(PermissionRegistrar::class)->getPermissionsTeamId();
        expect($resolved)->toBe($this->orgLow->id);
    });

    it('does not set a team id for non-super-admin users on apex', function () {
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $regular = User::factory()->for($this->orgLow)->create();
        $this->actingAs($regular);

        $request = Request::create('https://skv1.test/dashboard', 'GET');
        app(ResolveTenant::class)->handle($request, fn () => new Response);

        expect(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBeNull();
    });
});

describe('User::isSuperAdmin role-based check', function () {
    it('returns true when the user has the super_admin role in any org', function () {
        $org = Organisation::factory()->create(['slug' => 'role-check']);
        $user = User::factory()->for($org)->create();

        app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
        $user->assignRole('super_admin');

        expect($user->fresh()->isSuperAdmin())->toBeTrue();
    });

    it('returns false when the user has no super_admin role assignment', function () {
        $org = Organisation::factory()->create(['slug' => 'no-role']);
        $user = User::factory()->for($org)->create();

        expect($user->isSuperAdmin())->toBeFalse();
    });

    it('grants super-admin access via Spatie permissions only', function () {
        $org = Organisation::factory()->create(['slug' => 'gate-check']);
        // User with no super_admin role assignment.
        $user = User::factory()->for($org)->create();

        app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
        expect($user->can('users.delete'))->toBeFalse();
    });
});
