<?php

use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('auto-fills organisation_id on create within tenant context', function () {
    $org = Organisation::factory()->create();
    app()->instance('currentOrganisation', $org);

    $user = User::factory()->create(['organisation_id' => null]);

    expect($user->organisation_id)->toBe($org->id);
});

it('hides rows from other organisations for non-super users', function () {
    [$a, $b] = Organisation::factory()->count(2)->create();
    User::factory()->for($a)->count(2)->create();
    User::factory()->for($b)->count(1)->create();

    $actor = User::factory()->for($a)->create();

    app()->instance('currentOrganisation', $a);
    $this->actingAs($actor);

    expect(User::count())->toBe(3); // 2 + actor in org a (org b's user is hidden)
});

it('lets super_admin see across organisations', function () {
    [$a, $b] = Organisation::factory()->count(2)->create();
    User::factory()->for($a)->count(2)->create();
    User::factory()->for($b)->count(2)->create();

    $super = User::factory()->for($a)->superAdmin()->create();

    app()->instance('currentOrganisation', $a);
    $this->actingAs($super);

    expect(User::count())->toBeGreaterThanOrEqual(5); // 2 + 2 + super
});

it('exposes withoutTenantScope to bypass the global scope', function () {
    [$a, $b] = Organisation::factory()->count(2)->create();
    User::factory()->for($a)->count(2)->create();
    User::factory()->for($b)->count(3)->create();

    $actor = User::factory()->for($a)->create();
    app()->instance('currentOrganisation', $a);
    $this->actingAs($actor);

    expect(User::withoutTenantScope()->count())->toBe(6);
});

it('resolves the auth user from session without recursing in the tenant scope', function () {
    $org = Organisation::factory()->create();
    $user = User::factory()->for($org)->create();
    app()->instance('currentOrganisation', $org);

    auth()->login($user);
    auth()->forgetUser();

    $resolved = auth()->user();

    expect($resolved)->not->toBeNull();
    expect($resolved->id)->toBe($user->id);
});
