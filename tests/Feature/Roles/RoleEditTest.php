<?php

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

    Livewire::test('roles.edit', ['role' => $role])
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

    Livewire::test('roles.edit', ['role' => $role])
        ->set('selectedPermissions', [])
        ->call('save')
        ->assertRedirect(route('roles.index'));

    expect($role->fresh()->permissions)->toHaveCount(0);
});

it('rejects names that are not alpha_dash', function () {
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

    $this->actingAs($this->actor);

    Livewire::test('roles.edit', ['role' => $role])
        ->set('name', 'has spaces')
        ->call('save')
        ->assertHasErrors(['name']);

    expect($role->fresh()->name)->toBe('editor');
});

it('rejects reserved role names', function () {
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

    $this->actingAs($this->actor);

    foreach (['super_admin', 'organisation_admin', 'member'] as $reserved) {
        Livewire::test('roles.edit', ['role' => $role])
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

    Livewire::test('roles.edit', ['role' => $role])
        ->set('name', 'template_only')
        ->call('save')
        ->assertHasErrors(['name']);

    expect($role->fresh()->name)->toBe('editor');
});

it('uses the "rol zonder organisatie" wording in the template-clash error', function () {
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);
    Role::create(['name' => 'template_only', 'guard_name' => 'web', 'team_id' => null]);

    $this->actingAs($this->actor);

    $component = Livewire::test('roles.edit', ['role' => $role])
        ->set('name', 'template_only')
        ->call('save');

    expect($component->errors()->first('name'))->toContain('rol zonder organisatie');
});

it('rejects a name that already exists in the same team', function () {
    Role::create(['name' => 'redactor', 'guard_name' => 'web', 'team_id' => $this->org->id]);
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

    $this->actingAs($this->actor);

    Livewire::test('roles.edit', ['role' => $role])
        ->set('name', 'redactor')
        ->call('save')
        ->assertHasErrors(['name']);

    expect($role->fresh()->name)->toBe('editor');
});

it('allows saving without changing the name (ignores unique on self)', function () {
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

    $this->actingAs($this->actor);

    Livewire::test('roles.edit', ['role' => $role])
        ->set('name', 'editor')
        ->call('save')
        ->assertHasNoErrors();
});

it('rejects a name held by a soft-deleted role in the same team (DB unique constraint)', function () {
    $stale = Role::create(['name' => 'tombstone', 'guard_name' => 'web', 'team_id' => $this->org->id]);
    $stale->delete();

    $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

    $this->actingAs($this->actor);

    Livewire::test('roles.edit', ['role' => $role])
        ->set('name', 'tombstone')
        ->call('save')
        ->assertHasErrors(['name']);

    expect($role->fresh()->name)->toBe('editor');
});

describe('Super admin template editing', function () {
    it('opens the edit page for a template role when actor is super_admin', function () {
        $superAdmin = User::factory()->for($this->org)->create();
        $superAdmin->assignRole('super_admin');

        $template = Role::where('name', 'organisation_admin')->whereNull('team_id')->firstOrFail();

        $this->actingAs($superAdmin);

        $this->get(route('roles.edit', $template))
            ->assertOk()
            ->assertSee('organisation_admin');
    });

    it('lets super_admin save a template role with the same (reserved) name and new permissions', function () {
        $superAdmin = User::factory()->for($this->org)->create();
        $superAdmin->assignRole('super_admin');

        $template = Role::where('name', 'organisation_admin')->whereNull('team_id')->firstOrFail();
        $newPerm = Permission::where('name', 'users.view')->firstOrFail();

        $this->actingAs($superAdmin);

        Livewire::test('roles.edit', ['role' => $template])
            ->set('name', 'organisation_admin')
            ->set('selectedPermissions', [$newPerm->id])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('roles.index'));

        expect($template->fresh()->permissions->pluck('name')->all())->toBe(['users.view']);
    });

    it('scopes uniqueness to the role\'s own team_id (template stays NULL) so a same-named per-org copy does not clash', function () {
        // Spatie's Role::create rejects a template + per-org pair as duplicate, so use
        // firstOrCreate (Eloquent's) like OrganisationObserver does to bypass that check.
        $template = Role::firstOrCreate(['name' => 'shared_role', 'guard_name' => 'web', 'team_id' => null]);
        Role::firstOrCreate(['name' => 'shared_role', 'guard_name' => 'web', 'team_id' => $this->org->id]);

        $superAdmin = User::factory()->for($this->org)->create();
        $superAdmin->assignRole('super_admin');
        $this->actingAs($superAdmin);

        // Saving the template with its existing name must succeed: uniqueness scope must
        // follow the role's own team_id (NULL), not fall back to the tenant's id.
        Livewire::test('roles.edit', ['role' => $template])
            ->set('name', 'shared_role')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('roles.index'));
    });

    it('does NOT render the organisation select for a regular org_admin editing a per-org role', function () {
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);
        $this->actingAs($this->actor);

        $this->get(route('roles.edit', $role))
            ->assertOk()
            ->assertDontSee('wire:model="organisationId"', false);
    });

    it('does NOT render the organisation select for a super_admin editing a template role', function () {
        $superAdmin = User::factory()->for($this->org)->create();
        $superAdmin->assignRole('super_admin');

        $template = Role::where('name', 'organisation_admin')->whereNull('team_id')->firstOrFail();

        $this->actingAs($superAdmin);

        $this->get(route('roles.edit', $template))
            ->assertOk()
            ->assertDontSee('wire:model="organisationId"', false);
    });

    it('renders the organisation select for a super_admin editing a per-org role', function () {
        $superAdmin = User::factory()->for($this->org)->create();
        $superAdmin->assignRole('super_admin');

        $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

        $this->actingAs($superAdmin);

        $this->get(route('roles.edit', $role))
            ->assertOk()
            ->assertSee('wire:model="organisationId"', false);
    });

    it('lets super_admin move a per-org role to a different organisation when no users are attached', function () {
        $otherOrg = Organisation::factory()->create(['slug' => 'demo2']);
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

        $superAdmin = User::factory()->for($this->org)->create();
        $superAdmin->assignRole('super_admin');

        $this->actingAs($superAdmin);

        Livewire::test('roles.edit', ['role' => $role])
            ->set('organisationId', $otherOrg->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('roles.index'));

        expect($role->fresh()->team_id)->toBe($otherOrg->id);
    });

    it('blocks super_admin from moving a role that still has users attached', function () {
        $otherOrg = Organisation::factory()->create(['slug' => 'demo2']);
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);
        $member = User::factory()->for($this->org)->create();
        $member->assignRole($role);

        $superAdmin = User::factory()->for($this->org)->create();
        $superAdmin->assignRole('super_admin');

        $this->actingAs($superAdmin);

        Livewire::test('roles.edit', ['role' => $role])
            ->set('organisationId', $otherOrg->id)
            ->call('save')
            ->assertHasErrors(['organisationId']);

        expect($role->fresh()->team_id)->toBe($this->org->id);
    });

    it('rejects a move when the target org already has a role with the same name', function () {
        $otherOrg = Organisation::factory()->create(['slug' => 'demo2']);
        Role::firstOrCreate(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $otherOrg->id]);

        $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

        $superAdmin = User::factory()->for($this->org)->create();
        $superAdmin->assignRole('super_admin');

        $this->actingAs($superAdmin);

        Livewire::test('roles.edit', ['role' => $role])
            ->set('organisationId', $otherOrg->id)
            ->call('save')
            ->assertHasErrors(['name']);

        expect($role->fresh()->team_id)->toBe($this->org->id);
    });

    it('ignores organisationId from a non-super-admin payload (no privilege escalation)', function () {
        $otherOrg = Organisation::factory()->create(['slug' => 'demo2']);
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

        $this->actingAs($this->actor);

        // Even though the org_admin payload sets organisationId, the server-side
        // $wantsMove gate requires isSuperAdmin() so team_id must not change.
        Livewire::test('roles.edit', ['role' => $role])
            ->set('organisationId', $otherOrg->id)
            ->call('save');

        expect($role->fresh()->team_id)->toBe($this->org->id);
    });

});

describe('Create mode', function () {
    it('mounts in create mode without a role argument', function () {
        $this->actingAs($this->actor);

        Livewire::test('roles.edit')
            ->assertOk()
            ->assertSet('role', null)
            ->assertSet('name', '')
            ->assertSet('selectedPermissions', []);
    });

    it('creates a per-org role with selected permissions and redirects to index', function () {
        $perm = Permission::where('name', 'users.view')->first();

        $this->actingAs($this->actor);

        Livewire::test('roles.edit')
            ->set('name', 'editor')
            ->set('selectedPermissions', [$perm->id])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('roles.index'));

        $created = Role::where('name', 'editor')->where('team_id', $this->org->id)->first();
        expect($created)->not->toBeNull()
            ->and($created->permissions->pluck('name')->all())->toBe(['users.view']);
    });

    it('opens the create page for an authorized user', function () {
        $this->actingAs($this->actor);

        $this->get(route('roles.create'))
            ->assertOk()
            ->assertSee('Nieuwe rol');
    });

    it('returns 403 on the create page when actor lacks roles.manage', function () {
        $regular = User::factory()->for($this->org)->create();

        $this->actingAs($regular);

        $this->get(route('roles.create'))->assertForbidden();
    });

    it('rejects empty name on create', function () {
        $this->actingAs($this->actor);

        Livewire::test('roles.edit')
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name']);

        expect(Role::where('team_id', $this->org->id)->where('name', '')->exists())->toBeFalse();
    });

    it('rejects reserved role names on create', function () {
        $this->actingAs($this->actor);

        foreach (['super_admin', 'organisation_admin', 'member'] as $reserved) {
            Livewire::test('roles.edit')
                ->set('name', $reserved)
                ->call('save')
                ->assertHasErrors(['name']);
        }
    });

    it('rejects names that are not alpha_dash on create', function () {
        $this->actingAs($this->actor);

        Livewire::test('roles.edit')
            ->set('name', 'has spaces')
            ->call('save')
            ->assertHasErrors(['name']);
    });

    it('rejects a name that clashes with a template role on create', function () {
        Role::create(['name' => 'template_only', 'guard_name' => 'web', 'team_id' => null]);

        $this->actingAs($this->actor);

        Livewire::test('roles.edit')
            ->set('name', 'template_only')
            ->call('save')
            ->assertHasErrors(['name']);

        expect(Role::where('name', 'template_only')->where('team_id', $this->org->id)->exists())->toBeFalse();
    });

    it('rejects a name that already exists in the same team on create', function () {
        Role::create(['name' => 'redactor', 'guard_name' => 'web', 'team_id' => $this->org->id]);

        $this->actingAs($this->actor);

        Livewire::test('roles.edit')
            ->set('name', 'redactor')
            ->call('save')
            ->assertHasErrors(['name']);
    });
});
