<?php

use App\Livewire\Roles\Edit;
use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    config(['app.apex_domain' => 'skv1.test']);
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->org = Organisation::factory()->create(['slug' => 'demo1']);
    app()->instance('currentOrganisation', $this->org);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);

    $this->actor = User::factory()->for($this->org)->create();
    $this->actor->assignRole('organisation_admin');
});

it('opens the edit page for a per-org role', function () {
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

    $this->actingAs($this->actor);

    $this->get(route('roles.edit', $role))
        ->assertOk()
        ->assertSee('editor');
});

it('returns 403 for a template role', function () {
    $template = Role::where('name', 'organisation_admin')->whereNull('team_id')->firstOrFail();

    $this->actingAs($this->actor);

    $this->get(route('roles.edit', $template))->assertForbidden();
});

it('returns 403 when actor lacks roles.manage', function () {
    $regular = User::factory()->for($this->org)->create();
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

    $this->actingAs($regular);

    $this->get(route('roles.edit', $role))->assertForbidden();
});

it('returns 403 for a role from another organisation', function () {
    $other = Organisation::factory()->create(['slug' => 'demo2']);
    $role = Role::create(['name' => 'foreign', 'guard_name' => 'web', 'team_id' => $other->id]);

    $this->actingAs($this->actor);

    $this->get(route('roles.edit', $role))->assertForbidden();
});
