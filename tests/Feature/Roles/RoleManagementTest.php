<?php

use App\Livewire\Roles\Index;
use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;
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

describe('RolePolicy', function () {
    it('allows org_admin to view and manage roles', function () {
        $this->actingAs($this->actor);
        expect(Gate::allows('viewAny', Role::class))->toBeTrue()
            ->and(Gate::allows('create', Role::class))->toBeTrue();
    });

    it('forbids modifying the organisation_admin template role', function () {
        $template = Role::where('name', 'organisation_admin')->whereNull('team_id')->firstOrFail();
        $this->actingAs($this->actor);

        expect(Gate::allows('update', $template))->toBeFalse()
            ->and(Gate::allows('delete', $template))->toBeFalse();
    });

    it('allows org_admin to update and delete per-org custom roles', function () {
        $this->actingAs($this->actor);
        $custom = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

        expect(Gate::allows('update', $custom))->toBeTrue()
            ->and(Gate::allows('delete', $custom))->toBeTrue();
    });

    it('forbids regular users (without roles.manage) from creating roles', function () {
        $regular = User::factory()->for($this->org)->create();
        $this->actingAs($regular);

        expect(Gate::allows('create', Role::class))->toBeFalse();
    });
});

describe('Roles Index Livewire', function () {
    it('lists the template role plus per-org roles', function () {
        Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->assertSee('organisation_admin')
            ->assertSee('editor');
    });

    it('does not list roles belonging to other organisations', function () {
        $other = Organisation::factory()->create(['slug' => 'demo2']);
        Role::create(['name' => 'foreign-role', 'guard_name' => 'web', 'team_id' => $other->id]);

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->assertDontSee('foreign-role');
    });

    it('creates a per-org role with selected permissions', function () {
        $perm = Permission::where('name', 'users.view')->first();

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('newRoleName', 'editor')
            ->set('newRolePermissions', [$perm->id])
            ->call('createRole')
            ->assertHasNoErrors();

        $created = Role::where('name', 'editor')->where('team_id', $this->org->id)->first();
        expect($created)->not->toBeNull()
            ->and($created->permissions->pluck('name')->all())->toContain('users.view');
    });

    it('soft-deletes a per-org role with no users attached', function () {
        $role = Role::create(['name' => 'tobegone', 'guard_name' => 'web', 'team_id' => $this->org->id]);
        $id = $role->id;

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->call('deleteRole', $id)
            ->assertHasNoErrors();

        expect(\App\Models\Role::find($id))->toBeNull();
        $this->assertSoftDeleted('roles', ['id' => $id]);
    });

    it('refuses to delete a role that still has users attached', function () {
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);
        $member = User::factory()->for($this->org)->create();
        $member->assignRole($role);

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->call('deleteRole', $role->id)
            ->assertHasNoErrors();

        // Role must still exist and must NOT be soft-deleted
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'deleted_at' => null]);
    });

    it('exposes users_count on each listed role', function () {
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);
        $member = User::factory()->for($this->org)->create();
        $member->assignRole($role);

        $this->actingAs($this->actor);

        $component = Livewire::test(Index::class);

        $listed = collect($component->viewData('roles'))->firstWhere('id', $role->id);
        expect($listed->users_count)->toBe(1);
    });

    it('refuses to delete the template role', function () {
        $template = Role::where('name', 'organisation_admin')->whereNull('team_id')->firstOrFail();

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->call('deleteRole', $template->id)
            ->assertStatus(403);

        expect(Role::find($template->id))->not->toBeNull();
    });
});
