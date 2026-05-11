<?php

use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
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

    it('allows super_admin to update and delete template (geen organisatie) roles', function () {
        $superAdmin = User::factory()->for($this->org)->create();
        $superAdmin->assignRole('super_admin');

        $template = Role::where('name', 'organisation_admin')->whereNull('team_id')->firstOrFail();

        $this->actingAs($superAdmin);

        expect(Gate::allows('update', $template))->toBeTrue()
            ->and(Gate::allows('delete', $template))->toBeTrue();
    });
});

describe('Roles Index Livewire', function () {
    it('lists the template role plus per-org roles', function () {
        Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

        $this->actingAs($this->actor);

        Livewire::test('roles.index')
            ->assertSee('organisation_admin')
            ->assertSee('editor');
    });

    it('does not list roles belonging to other organisations', function () {
        $other = Organisation::factory()->create(['slug' => 'demo2']);
        Role::create(['name' => 'foreign-role', 'guard_name' => 'web', 'team_id' => $other->id]);

        $this->actingAs($this->actor);

        Livewire::test('roles.index')
            ->assertDontSee('foreign-role');
    });

    it('soft-deletes a per-org role with no users attached', function () {
        $role = Role::create(['name' => 'tobegone', 'guard_name' => 'web', 'team_id' => $this->org->id]);
        $id = $role->id;

        $this->actingAs($this->actor);

        Livewire::test('roles.index')
            ->call('deleteRole', $id)
            ->assertHasNoErrors();

        expect(App\Models\Role::find($id))->toBeNull();
        $this->assertSoftDeleted('roles', ['id' => $id]);
    });

    it('refuses to delete a role that still has users attached', function () {
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);
        $member = User::factory()->for($this->org)->create();
        $member->assignRole($role);

        $this->actingAs($this->actor);

        Livewire::test('roles.index')
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

        $component = Livewire::test('roles.index');

        $listed = collect($component->viewData('roles'))->firstWhere('id', $role->id);
        expect($listed->users_count)->toBe(1);
    });

    it('hides a template when a per-org copy with the same name exists in this tenant', function () {
        // The OrganisationObserver auto-created a per-org organisation_admin in beforeEach.
        $this->actingAs($this->actor);

        $component = Livewire::test('roles.index');
        $rows = collect($component->viewData('roles'));
        $orgAdminRows = $rows->where('name', 'organisation_admin');

        expect($orgAdminRows)->toHaveCount(1);
        expect($orgAdminRows->first()->team_id)->toBe($this->org->id);
    });

    it('still shows a template that has no per-org copy in this tenant', function () {
        // Drop the auto-created per-org copy so only the template remains visible.
        Role::where('name', 'organisation_admin')->where('team_id', $this->org->id)->delete();

        $this->actingAs($this->actor);

        Livewire::test('roles.index')
            ->assertSee('organisation_admin');
    });

    it('refuses to delete the template role', function () {
        $template = Role::where('name', 'organisation_admin')->whereNull('team_id')->firstOrFail();

        $this->actingAs($this->actor);

        Livewire::test('roles.index')
            ->call('deleteRole', $template->id)
            ->assertStatus(403);

        expect(Role::find($template->id))->not->toBeNull();
    });

    it('renders a "Nieuwe rol" button visible to authorized users', function () {
        $this->actingAs($this->actor);

        $this->get(route('roles.index'))
            ->assertOk()
            ->assertSee('Nieuwe rol')
            ->assertSee(route('roles.create'));
    });

    it('does not render the inline create-form anymore', function () {
        $this->actingAs($this->actor);

        $this->get(route('roles.index'))
            ->assertOk()
            ->assertDontSee('Nieuwe rol aanmaken');
    });

    it('renders the "Geen organisatie" badge for template roles (no longer "Sjabloon")', function () {
        // Force the template to be visible by removing the per-org copy auto-created in beforeEach.
        Role::where('name', 'organisation_admin')->where('team_id', $this->org->id)->delete();

        $this->actingAs($this->actor);

        $this->get(route('roles.index'))
            ->assertOk()
            ->assertSee('Geen organisatie')
            ->assertDontSee('Sjabloon');
    });

    it('shows an empty-state callout when there are no visible roles', function () {
        // Ruim alle template-rollen op zodat de query een lege collectie oplevert.
        // Gate::before bypasses the policy so the actor can still reach the page
        // even though forceDelete() removes every role (including theirs).
        Gate::before(fn () => true);
        Role::query()->forceDelete();

        $this->actingAs($this->actor);

        $this->get(route('roles.index'))
            ->assertOk()
            ->assertSee('Geen rollen gevonden.');
    });
});
