<?php

use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->org = Organisation::factory()->create(['slug' => 'a']);
    app()->instance('currentOrganisation', $this->org);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
});

it('returns 401 for an unauthenticated request without a bearer token', function () {
    $this->getJson('/health-check')->assertStatus(401);
});

it('returns 200 + JSON shape for a super_admin', function () {
    $super = User::factory()->superAdmin()->create(['organisation_id' => null]);

    $this->actingAs($super)
        ->getJson('/health-check')
        ->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'checks' => ['database', 'queue', 'mail', 'backup'],
        ])
        ->assertJsonPath('status', 'ok');
});

it('returns 200 with a matching bearer health-check key', function () {
    config(['services.health_check.key' => 'secret-token-xyz']);

    $this->withHeader('Authorization', 'Bearer secret-token-xyz')
        ->getJson('/health-check')
        ->assertStatus(200)
        ->assertJsonPath('status', 'ok');
});

it('rejects a wrong bearer key', function () {
    config(['services.health_check.key' => 'expected']);

    $this->withHeader('Authorization', 'Bearer not-the-key')
        ->getJson('/health-check')
        ->assertStatus(401);
});

it('returns 403 for a logged-in user without system.health permission', function () {
    $regular = User::factory()->for($this->org)->create();

    $this->actingAs($regular)
        ->getJson('/health-check')
        ->assertStatus(403);
});
