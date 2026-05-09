<?php

// tests/Feature/Models/RoleSoftDeleteTest.php

use App\Models\Role as AppRole;
use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->org = Organisation::factory()->create(['slug' => 'demo1']);
    app()->instance('currentOrganisation', $this->org);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
});

it('resolves Spatie role-class to App\\Models\\Role', function () {
    $role = AppRole::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

    expect($role)->toBeInstanceOf(\App\Models\Role::class);
});

it('soft-deletes a role and excludes it from default queries', function () {
    $role = AppRole::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);
    $id = $role->id;

    $role->delete();

    $this->assertSoftDeleted('roles', ['id' => $id]);
    expect($role->fresh()->trashed())->toBeTrue();
    expect(AppRole::find($id))->toBeNull();
    expect(AppRole::withTrashed()->find($id))->not->toBeNull();
});

it('keeps model_has_roles pivot rows after soft-delete', function () {
    $role = AppRole::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);
    $member = User::factory()->for($this->org)->create();
    $member->assignRole($role);

    $pivotCount = \Illuminate\Support\Facades\DB::table('model_has_roles')
        ->where('role_id', $role->id)
        ->count();
    expect($pivotCount)->toBe(1);

    $role->delete();

    $pivotCountAfter = \Illuminate\Support\Facades\DB::table('model_has_roles')
        ->where('role_id', $role->id)
        ->count();
    expect($pivotCountAfter)->toBe(1);
});

it('hides a soft-deleted role from a user\'s active roles', function () {
    $role = AppRole::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);
    $member = User::factory()->for($this->org)->create();
    $member->assignRole($role);

    expect($member->fresh()->hasRole('editor'))->toBeTrue();

    $role->delete();
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    expect($member->fresh()->hasRole('editor'))->toBeFalse();
});
