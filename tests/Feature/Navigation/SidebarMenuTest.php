<?php

use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    config(['app.apex_domain' => 'skv1.test']);
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->org = Organisation::factory()->create(['slug' => 'demo1']);
    app()->instance('currentOrganisation', $this->org);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
});

it('shows admin links to an organisation_admin', function () {
    $admin = User::factory()->for($this->org)->create();
    $admin->assignRole('organisation_admin');

    $this->actingAs($admin)
        ->get('https://demo1.skv1.test/dashboard')
        ->assertOk()
        ->assertSee('/admin/users', false)
        ->assertSee('/admin/roles', false)
        ->assertSee('/admin/activity', false);
});

it('hides admin links from a user without permissions', function () {
    $regular = User::factory()->for($this->org)->create();

    $this->actingAs($regular)
        ->get('https://demo1.skv1.test/dashboard')
        ->assertOk()
        ->assertDontSee('/admin/users')
        ->assertDontSee('/admin/roles')
        ->assertDontSee('/admin/activity');
});

it('shows admin links to a super-admin', function () {
    $super = User::factory()->superAdmin()->create();

    $this->actingAs($super)
        ->get('https://demo1.skv1.test/dashboard')
        ->assertOk()
        ->assertSee('/admin/users', false)
        ->assertSee('/admin/roles', false)
        ->assertSee('/admin/activity', false);
});
