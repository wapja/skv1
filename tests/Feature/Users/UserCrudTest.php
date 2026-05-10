<?php

use App\Livewire\Users\Edit;
use App\Livewire\Users\Index;
use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    config(['app.apex_domain' => 'skv1.test']);
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->org = Organisation::factory()->create(['slug' => 'demo1']);
    app()->instance('currentOrganisation', $this->org);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);

    $this->actor = User::factory()->for($this->org)->create([
        'first_name' => 'Admin',
        'last_name' => 'Een',
        'email' => 'admin@demo1.local',
    ]);
    $this->actor->assignRole('organisation_admin');
});

describe('Users Index Livewire', function () {
    it('lists users in the current organisation', function () {
        $other = User::factory()->for($this->org)->create(['first_name' => 'Bob', 'last_name' => 'Tester', 'email' => 'bob@demo1.local']);

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->assertSee('Bob')
            ->assertSee('admin@demo1.local');
    });

    it('hides users from other organisations', function () {
        $other = Organisation::factory()->create(['slug' => 'demo2']);
        User::factory()->for($other)->create(['first_name' => 'Outsider', 'last_name' => 'Een', 'email' => 'out@demo2.local']);

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->assertDontSee('Outsider');
    });

    it('filters users by status', function () {
        User::factory()->for($this->org)->create(['first_name' => 'Active', 'middle_name' => null, 'last_name' => 'Alice', 'status' => 'active']);
        User::factory()->for($this->org)->pendingActivation()->create(['first_name' => 'Pending', 'middle_name' => null, 'last_name' => 'Pete']);
        User::factory()->for($this->org)->disabled()->create(['first_name' => 'Disabled', 'middle_name' => null, 'last_name' => 'Dan']);

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('filters.status', 'pending_activation')
            ->assertSee('Pending Pete')
            ->assertDontSee('Active Alice')
            ->assertDontSee('Disabled Dan');
    });

    it('hides unselected columns and shows selected ones', function () {
        User::factory()->for($this->org)->create([
            'first_name' => 'Bob',
            'last_name' => 'Tester',
            'email' => 'bob@demo1.local',
            'phone' => '0612345678',
            'internal_id' => 'EMP-42',
        ]);

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('selectedColumns', ['name', 'phone'])
            ->assertSee('0612345678')
            ->assertDontSee('bob@demo1.local')
            ->assertDontSee('EMP-42');
    });

    it('sanitises unknown column keys from session state', function () {
        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('selectedColumns', ['name', 'bogus_key', 'email'])
            ->assertSet('selectedColumns', ['name', 'email']);
    });

    it('soft-deletes a user via the delete action', function () {
        $target = User::factory()->for($this->org)->create();

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->call('delete', $target->id)
            ->assertHasNoErrors();

        expect(User::find($target->id))->toBeNull()
            ->and(User::withTrashed()->find($target->id)->trashed())->toBeTrue();
    });

    it('forbids deleting yourself', function () {
        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->call('delete', $this->actor->id)
            ->assertStatus(403);

        expect($this->actor->fresh())->not->toBeNull();
    });

    it('paginates users with a default of 10 per page', function () {
        User::factory()->count(15)->for($this->org)->create();

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->assertSet('perPage', 10)
            ->assertViewHas('users', fn ($users) => $users->count() === 10 && $users->total() >= 16);
    });

    it('caps results when perPage is changed to 5', function () {
        User::factory()->count(15)->for($this->org)->create();

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('perPage', 5)
            ->assertViewHas('users', fn ($users) => $users->count() === 5);
    });

    it('clamps an invalid perPage value back to 10', function () {
        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('perPage', 7)
            ->assertSet('perPage', 10);
    });

    it('resets to page 1 when perPage changes', function () {
        User::factory()->count(30)->for($this->org)->create();

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->call('gotoPage', 2)
            ->assertSet('paginators.page', 2)
            ->set('perPage', 25)
            ->assertSet('paginators.page', 1);
    });

    it('defaults to last_name → first_name when no sort is selected', function () {
        User::factory()->for($this->org)->create(['first_name' => 'Anna',  'last_name' => 'Zilver']);
        User::factory()->for($this->org)->create(['first_name' => 'Bart',  'last_name' => 'Aap']);
        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->assertSet('sortColumn', null)
            ->assertViewHas('users', function ($users) {
                $rows = $users->getCollection()->pluck('last_name')->all();

                return $rows[0] === 'Aap' && in_array('Zilver', $rows, true);
            });
    });

    it('sorts by name asc, then desc, then back to default on third click', function () {
        User::factory()->for($this->org)->create(['first_name' => 'A', 'last_name' => 'A']);
        User::factory()->for($this->org)->create(['first_name' => 'Z', 'last_name' => 'Z']);
        $this->actingAs($this->actor);

        $component = Livewire::test(Index::class)
            ->call('sort', 'name')
            ->assertSet('sortColumn', 'name')
            ->assertSet('sortDirection', 'asc')
            ->call('sort', 'name')
            ->assertSet('sortColumn', 'name')
            ->assertSet('sortDirection', 'desc')
            ->call('sort', 'name')
            ->assertSet('sortColumn', null);
    });

    it('sorts by email asc and desc when toggled', function () {
        User::factory()->for($this->org)->create(['email' => 'aaa@demo1.local']);
        User::factory()->for($this->org)->create(['email' => 'zzz@demo1.local']);
        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->call('sort', 'email')
            ->assertViewHas('users', fn ($users) => $users->getCollection()->first()->email === 'aaa@demo1.local')
            ->call('sort', 'email')
            ->assertViewHas('users', fn ($users) => $users->getCollection()->first()->email === 'zzz@demo1.local');
    });

    it('clamps an unknown sortColumn back to null', function () {
        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('sortColumn', 'bogus_field')
            ->assertSet('sortColumn', null);
    });

    it('filters email case-insensitively via ILIKE contains', function () {
        User::factory()->for($this->org)->create(['email' => 'Alice@demo1.local']);
        User::factory()->for($this->org)->create(['email' => 'BOB@demo1.local']);
        User::factory()->for($this->org)->create(['email' => 'carol@other.test']);
        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('filters.email', 'ALICE')
            ->assertSee('Alice@demo1.local')
            ->assertDontSee('BOB@demo1.local')
            ->assertDontSee('carol@other.test')
            ->assertDontSee('admin@demo1.local');
    });

    it('filters phone via ILIKE contains', function () {
        User::factory()->for($this->org)->create(['email' => 'alice@demo1.local', 'phone' => '0612345678']);
        User::factory()->for($this->org)->create(['email' => 'bob@demo1.local',   'phone' => '0699999999']);
        User::factory()->for($this->org)->create(['email' => 'carol@demo1.local', 'phone' => '0611111111']);
        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('filters.phone', '0699')
            ->assertSee('bob@demo1.local')
            ->assertDontSee('alice@demo1.local')
            ->assertDontSee('carol@demo1.local');
    });

    it('filters internal_id case-insensitively via ILIKE contains', function () {
        User::factory()->for($this->org)->create(['email' => 'alice@demo1.local', 'internal_id' => 'EMP-1']);
        User::factory()->for($this->org)->create(['email' => 'bob@demo1.local',   'internal_id' => 'EMP-2']);
        User::factory()->for($this->org)->create(['email' => 'carol@demo1.local', 'internal_id' => 'CON-9']);
        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('filters.internal_id', 'emp-')
            ->assertSee('alice@demo1.local')
            ->assertSee('bob@demo1.local')
            ->assertDontSee('carol@demo1.local');
    });

    it('filters address case-insensitively via ILIKE contains', function () {
        User::factory()->for($this->org)->create(['email' => 'alice@demo1.local', 'address' => 'Damrak 1, Amsterdam']);
        User::factory()->for($this->org)->create(['email' => 'bob@demo1.local',   'address' => 'Coolsingel 5, Rotterdam']);
        User::factory()->for($this->org)->create(['email' => 'carol@demo1.local', 'address' => 'Stationsplein, Eindhoven']);
        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('filters.address', 'amsterdam')
            ->assertSee('alice@demo1.local')
            ->assertDontSee('bob@demo1.local')
            ->assertDontSee('carol@demo1.local');
    });

    it('name filter matches first_name, middle_name, or last_name', function () {
        User::factory()->for($this->org)->create(['first_name' => 'Anna',  'middle_name' => null,   'last_name' => 'Zijlstra']);
        User::factory()->for($this->org)->create(['first_name' => 'Bart',  'middle_name' => 'Anna', 'last_name' => 'Pieters']);
        User::factory()->for($this->org)->create(['first_name' => 'Bert',  'middle_name' => null,   'last_name' => 'Anna']);
        User::factory()->for($this->org)->create(['first_name' => 'Carl',  'middle_name' => null,   'last_name' => 'Yssel']);
        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('filters.name', 'Anna')
            ->assertSee('Zijlstra')
            ->assertSee('Pieters')
            ->assertSee('Bert')
            ->assertDontSee('Yssel');
    });

    it('locale filter limits results to one locale', function () {
        User::factory()->for($this->org)->create(['email' => 'nl1@demo1.local', 'locale' => 'nl']);
        User::factory()->for($this->org)->create(['email' => 'nl2@demo1.local', 'locale' => 'nl']);
        User::factory()->for($this->org)->create(['email' => 'en1@demo1.local', 'locale' => 'en']);
        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('filters.locale', 'en')
            ->assertSee('en1@demo1.local')
            ->assertDontSee('nl1@demo1.local')
            ->assertDontSee('nl2@demo1.local')
            ->assertDontSee('admin@demo1.local');
    });

    it('start_date filter is "≥" — earlier dates are excluded', function () {
        User::factory()->for($this->org)->create(['email' => 'past@demo1.local',    'start_date' => '2025-01-15']);
        User::factory()->for($this->org)->create(['email' => 'cutoff@demo1.local',  'start_date' => '2026-01-01']);
        User::factory()->for($this->org)->create(['email' => 'future@demo1.local',  'start_date' => '2026-06-01']);
        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('filters.start_date', '2026-01-01')
            ->assertSee('cutoff@demo1.local')
            ->assertSee('future@demo1.local')
            ->assertDontSee('past@demo1.local');
    });

    it('sanitises unknown filter keys from session state', function () {
        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('filters', ['name' => 'Bob', 'bogus_key' => 'x', 'email' => 'demo'])
            ->assertSet('filters.name', 'Bob')
            ->assertSet('filters.email', 'demo')
            ->tap(function ($component) {
                expect(array_key_exists('bogus_key', $component->get('filters')))->toBeFalse();
            });
    });

    it('clamps invalid status filter values back to empty string', function () {
        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('filters.status', 'banana')
            ->assertSet('filters.status', '');
    });

    it('resets to page 1 when any filter changes', function () {
        User::factory()->count(30)->for($this->org)->create();
        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->call('gotoPage', 2)
            ->assertSet('paginators.page', 2)
            ->set('filters.name', 'x')
            ->assertSet('paginators.page', 1);
    });

    it('unselecting a column clears its active filter', function () {
        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('filters.email', 'demo1')
            ->assertSet('filters.email', 'demo1')
            ->set('selectedColumns', ['name', 'status'])
            ->assertSet('filters.email', '');
    });

    it('resets to page 1 on sort change', function () {
        User::factory()->count(30)->for($this->org)->create();
        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->call('gotoPage', 2)
            ->assertSet('paginators.page', 2)
            ->call('sort', 'email')
            ->assertSet('paginators.page', 1);
    });
});

describe('Users Edit Livewire', function () {
    it('updates a users name, email, status and locale', function () {
        $target = User::factory()->for($this->org)->create([
            'first_name' => 'Old',
            'last_name' => 'Name',
            'email' => 'old@demo1.local',
            'status' => 'active',
            'locale' => 'nl',
        ]);

        $this->actingAs($this->actor);

        Livewire::test(Edit::class, ['user' => $target])
            ->set('first_name', 'New')
            ->set('last_name', 'Name')
            ->set('email', 'new@demo1.local')
            ->set('status', 'disabled')
            ->set('locale', 'en')
            ->call('save')
            ->assertRedirect(route('users.index'));

        $target->refresh();

        expect($target->first_name)->toBe('New')
            ->and($target->last_name)->toBe('Name')
            ->and($target->email)->toBe('new@demo1.local')
            ->and($target->status)->toBe('disabled')
            ->and($target->locale)->toBe('en');
    });

    it('rejects invalid email', function () {
        $target = User::factory()->for($this->org)->create();
        $this->actingAs($this->actor);

        Livewire::test(Edit::class, ['user' => $target])
            ->set('email', 'not-an-email')
            ->call('save')
            ->assertHasErrors('email');
    });

    it('rejects invalid status enum', function () {
        $target = User::factory()->for($this->org)->create();
        $this->actingAs($this->actor);

        Livewire::test(Edit::class, ['user' => $target])
            ->set('status', 'banana')
            ->call('save')
            ->assertHasErrors('status');
    });

    it('forbids editing a super_admin user', function () {
        $superTarget = User::factory()->for($this->org)->superAdmin()->create();
        $this->actingAs($this->actor);

        Livewire::test(Edit::class, ['user' => $superTarget])
            ->assertStatus(403);
    });

    it('shows organisation_admin / test1 / test2 to org-admin editor', function () {
        $target = User::factory()->for($this->org)->create();

        $this->actingAs($this->actor);

        $component = Livewire::test(Edit::class, ['user' => $target]);

        expect($component->instance()->availableRoles())
            ->toBe([
                'organisation_admin' => __('Organisatie-admin'),
                'test1' => __('Test rol 1'),
                'test2' => __('Test rol 2'),
            ]);
    });

    it('shows super_admin additionally to super-admin editor', function () {
        $target = User::factory()->for($this->org)->create();

        $editor = User::factory()->superAdmin()->create([
            'email' => 'edit-super@example.local',
            'organisation_id' => null,
        ]);

        $this->actingAs($editor);

        $component = Livewire::test(Edit::class, ['user' => $target]);

        expect(array_keys($component->instance()->availableRoles()))
            ->toBe(['super_admin', 'organisation_admin', 'test1', 'test2']);
    });

    it('saves regular role assignments selected by an org-admin', function () {
        $target = User::factory()->for($this->org)->create();

        $this->actingAs($this->actor);

        Livewire::test(Edit::class, ['user' => $target])
            ->set('first_name', $target->first_name)
            ->set('last_name', $target->last_name)
            ->set('email', $target->email)
            ->set('start_date', $target->start_date->toDateString())
            ->set('roles', ['test1', 'test2'])
            ->call('save')
            ->assertHasNoErrors();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
        expect($target->fresh()->getRoleNames()->sort()->values()->all())
            ->toBe(['test1', 'test2']);
    });

    it('grants super_admin via UI and propagates cross-org', function () {
        $otherOrg = Organisation::factory()->create(['slug' => 'edit-other']);
        $target = User::factory()->for($this->org)->create();

        $editor = User::factory()->superAdmin()->create([
            'email' => 'edit-promoter@example.local',
            'organisation_id' => null,
        ]);

        $this->actingAs($editor);

        Livewire::test(Edit::class, ['user' => $target])
            ->set('first_name', $target->first_name)
            ->set('last_name', $target->last_name)
            ->set('email', $target->email)
            ->set('start_date', $target->start_date->toDateString())
            ->set('roles', ['super_admin'])
            ->call('save')
            ->assertHasNoErrors();

        foreach ([$this->org, $otherOrg] as $org) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
            expect($target->fresh()->hasRole('super_admin'))
                ->toBeTrue("expected super_admin in {$org->slug}");
        }
    });

    it('rejects a spoofed super_admin role from a non-super-admin editor', function () {
        $target = User::factory()->for($this->org)->create();

        $this->actingAs($this->actor);

        Livewire::test(Edit::class, ['user' => $target])
            ->set('first_name', $target->first_name)
            ->set('last_name', $target->last_name)
            ->set('email', $target->email)
            ->set('start_date', $target->start_date->toDateString())
            ->set('roles', ['super_admin'])
            ->call('save')
            ->assertHasErrors(['roles.0']);
    });

    it('allows an org-admin to demote themselves via self-edit', function () {
        $this->actingAs($this->actor);

        Livewire::test(Edit::class, ['user' => $this->actor])
            ->set('first_name', $this->actor->first_name)
            ->set('last_name', $this->actor->last_name)
            ->set('email', $this->actor->email)
            ->set('start_date', $this->actor->start_date->toDateString())
            ->set('roles', [])
            ->call('save')
            ->assertHasNoErrors();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
        expect($this->actor->fresh()->getRoleNames()->all())->toBe([]);
    });
});
