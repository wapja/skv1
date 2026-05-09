<?php

use App\Livewire\Roles\Edit;
use App\Models\Organisation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
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

it('saves a renamed role and synced permissions, then redirects to index', function () {
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);
    $role->givePermissionTo('users.view');

    $newPerm = Permission::where('name', 'users.update')->first();

    $this->actingAs($this->actor);

    Livewire::test(Edit::class, ['role' => $role])
        ->set('name', 'redactor')
        ->set('selectedPermissions', [$newPerm->id])
        ->call('save')
        ->assertRedirect(route('roles.index'));

    $role->refresh();
    expect($role->name)->toBe('redactor')
        ->and($role->permissions->pluck('name')->all())->toBe(['users.update']);
});

it('clears all permissions when saving with an empty selection', function () {
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);
    $role->givePermissionTo('users.view');

    $this->actingAs($this->actor);

    Livewire::test(Edit::class, ['role' => $role])
        ->set('selectedPermissions', [])
        ->call('save')
        ->assertRedirect(route('roles.index'));

    expect($role->fresh()->permissions)->toHaveCount(0);
});

it('rejects names that are not alpha_dash', function () {
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

    $this->actingAs($this->actor);

    Livewire::test(Edit::class, ['role' => $role])
        ->set('name', 'has spaces')
        ->call('save')
        ->assertHasErrors(['name']);

    expect($role->fresh()->name)->toBe('editor');
});

it('rejects reserved role names', function () {
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

    $this->actingAs($this->actor);

    foreach (['super_admin', 'organisation_admin', 'member'] as $reserved) {
        Livewire::test(Edit::class, ['role' => $role])
            ->set('name', $reserved)
            ->call('save')
            ->assertHasErrors(['name']);
    }

    expect($role->fresh()->name)->toBe('editor');
});

it('rejects a name that clashes with a template role name', function () {
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

    Role::create(['name' => 'template_only', 'guard_name' => 'web', 'team_id' => null]);

    $this->actingAs($this->actor);

    Livewire::test(Edit::class, ['role' => $role])
        ->set('name', 'template_only')
        ->call('save')
        ->assertHasErrors(['name']);

    expect($role->fresh()->name)->toBe('editor');
});

it('rejects a name that already exists in the same team', function () {
    Role::create(['name' => 'redactor', 'guard_name' => 'web', 'team_id' => $this->org->id]);
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

    $this->actingAs($this->actor);

    Livewire::test(Edit::class, ['role' => $role])
        ->set('name', 'redactor')
        ->call('save')
        ->assertHasErrors(['name']);

    expect($role->fresh()->name)->toBe('editor');
});

it('allows saving without changing the name (ignores unique on self)', function () {
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

    $this->actingAs($this->actor);

    Livewire::test(Edit::class, ['role' => $role])
        ->set('name', 'editor')
        ->call('save')
        ->assertHasNoErrors();
});

it('rejects a name held by a soft-deleted role in the same team (DB unique constraint)', function () {
    $stale = Role::create(['name' => 'tombstone', 'guard_name' => 'web', 'team_id' => $this->org->id]);
    $stale->delete();

    $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

    $this->actingAs($this->actor);

    Livewire::test(Edit::class, ['role' => $role])
        ->set('name', 'tombstone')
        ->call('save')
        ->assertHasErrors(['name']);

    expect($role->fresh()->name)->toBe('editor');
});

describe('Create mode', function () {
    it('mounts in create mode without a role argument', function () {
        $this->actingAs($this->actor);

        Livewire::test(Edit::class)
            ->assertOk()
            ->assertSet('role', null)
            ->assertSet('name', '')
            ->assertSet('selectedPermissions', []);
    });

    it('creates a per-org role with selected permissions and redirects to index', function () {
        $perm = Permission::where('name', 'users.view')->first();

        $this->actingAs($this->actor);

        Livewire::test(Edit::class)
            ->set('name', 'editor')
            ->set('selectedPermissions', [$perm->id])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('roles.index'));

        $created = Role::where('name', 'editor')->where('team_id', $this->org->id)->first();
        expect($created)->not->toBeNull()
            ->and($created->permissions->pluck('name')->all())->toBe(['users.view']);
    });
});
