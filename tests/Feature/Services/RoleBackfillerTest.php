<?php

use App\Models\Organisation;
use App\Models\Role;
use App\Models\User;
use App\Services\RoleBackfiller;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->org = Organisation::factory()->create(['slug' => 'backfill-test']);
    app()->instance('currentOrganisation', $this->org);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
});

it('creates per-org copies for the four propagated role names', function () {
    DB::table('roles')
        ->where('team_id', $this->org->id)
        ->whereIn('name', ['organisation_admin', 'super_admin', 'test1', 'test2'])
        ->delete();

    app(RoleBackfiller::class)->backfillExistingOrganisations();

    foreach (['organisation_admin', 'super_admin', 'test1', 'test2'] as $name) {
        $perOrg = Role::where('name', $name)->where('team_id', $this->org->id)->first();
        expect($perOrg)->not->toBeNull("missing per-org copy for {$name}");
    }
});

it('re-points pivots from template to per-org copy', function () {
    DB::table('roles')->where('team_id', $this->org->id)->delete();

    $template = Role::where('name', 'organisation_admin')->whereNull('team_id')->firstOrFail();
    $user = User::factory()->for($this->org)->create();
    DB::table('model_has_roles')->insert([
        'role_id' => $template->id,
        'model_type' => (new User)->getMorphClass(),
        'model_id' => $user->id,
        'team_id' => $this->org->id,
    ]);

    app(RoleBackfiller::class)->backfillExistingOrganisations();

    $perOrg = Role::where('name', 'organisation_admin')->where('team_id', $this->org->id)->firstOrFail();
    $pivotRoleId = DB::table('model_has_roles')
        ->where('model_id', $user->id)
        ->where('team_id', $this->org->id)
        ->value('role_id');

    expect($pivotRoleId)->toBe($perOrg->id);
});

it('is idempotent — running twice does not duplicate roles or pivots', function () {
    DB::table('roles')->where('team_id', $this->org->id)->delete();

    $user = User::factory()->for($this->org)->create();
    $template = Role::where('name', 'test1')->whereNull('team_id')->firstOrFail();
    DB::table('model_has_roles')->insert([
        'role_id' => $template->id,
        'model_type' => (new User)->getMorphClass(),
        'model_id' => $user->id,
        'team_id' => $this->org->id,
    ]);

    $service = app(RoleBackfiller::class);
    $service->backfillExistingOrganisations();
    $service->backfillExistingOrganisations();

    expect(Role::where('name', 'test1')->where('team_id', $this->org->id)->count())->toBe(1);

    $pivotCount = DB::table('model_has_roles')
        ->where('model_id', $user->id)
        ->where('team_id', $this->org->id)
        ->count();
    expect($pivotCount)->toBe(1);
});

it('does not touch per-org rollen with names outside the propagated list', function () {
    $custom = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);
    $custom->givePermissionTo('users.view');
    $beforePerms = $custom->permissions->pluck('name')->all();

    app(RoleBackfiller::class)->backfillExistingOrganisations();

    $custom->refresh();
    expect($custom->permissions->pluck('name')->all())->toBe($beforePerms);
});

it('leaves orphan pivots pointing to a non-existent org untouched', function () {
    $template = Role::where('name', 'organisation_admin')->whereNull('team_id')->firstOrFail();
    $user = User::factory()->for($this->org)->create();
    DB::table('model_has_roles')->insert([
        'role_id' => $template->id,
        'model_type' => (new User)->getMorphClass(),
        'model_id' => $user->id,
        'team_id' => 99999, // org that does not exist
    ]);

    app(RoleBackfiller::class)->backfillExistingOrganisations();

    $orphanStill = DB::table('model_has_roles')
        ->where('model_id', $user->id)
        ->where('team_id', 99999)
        ->value('role_id');

    expect($orphanStill)->toBe($template->id);
});
