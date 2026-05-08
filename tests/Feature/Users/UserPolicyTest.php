<?php

use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->orgA = Organisation::factory()->create(['slug' => 'a']);
    $this->orgB = Organisation::factory()->create(['slug' => 'b']);

    app()->instance('currentOrganisation', $this->orgA);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->orgA->id);

    $this->actor = User::factory()->for($this->orgA)->create();
    $this->actor->assignRole('organisation_admin');

    $this->sameOrgUser = User::factory()->for($this->orgA)->create();
    $this->otherOrgUser = User::factory()->for($this->orgB)->create();
    $this->superAdmin = User::factory()->for($this->orgA)->superAdmin()->create();
});

it('allows org_admin to view users in same organisation', function () {
    $this->actingAs($this->actor);

    expect(Gate::allows('view', $this->sameOrgUser))->toBeTrue()
        ->and(Gate::allows('viewAny', User::class))->toBeTrue();
});

it('forbids org_admin from viewing users of other organisations', function () {
    $this->actingAs($this->actor);

    expect(Gate::allows('view', $this->otherOrgUser))->toBeFalse();
});

it('allows org_admin to update users in same organisation', function () {
    $this->actingAs($this->actor);

    expect(Gate::allows('update', $this->sameOrgUser))->toBeTrue();
});

it('forbids org_admin from updating super_admin users', function () {
    $this->actingAs($this->actor);

    expect(Gate::allows('update', $this->superAdmin))->toBeFalse();
});

it('forbids org_admin from deleting themselves', function () {
    $this->actingAs($this->actor);

    expect(Gate::allows('delete', $this->actor))->toBeFalse();
});

it('forbids org_admin from deleting super_admin users', function () {
    $this->actingAs($this->actor);

    expect(Gate::allows('delete', $this->superAdmin))->toBeFalse();
});

it('allows super_admin to update any non-self user including across orgs', function () {
    $this->actingAs($this->superAdmin);

    expect(Gate::allows('update', $this->sameOrgUser))->toBeTrue()
        ->and(Gate::allows('update', $this->otherOrgUser))->toBeTrue();
});

it('forbids users without users.create permission from creating', function () {
    $regular = User::factory()->for($this->orgA)->create();
    $this->actingAs($regular);

    expect(Gate::allows('create', User::class))->toBeFalse();
});

it('allows org_admin to create users (has users.create via role)', function () {
    $this->actingAs($this->actor);

    expect(Gate::allows('create', User::class))->toBeTrue();
});
