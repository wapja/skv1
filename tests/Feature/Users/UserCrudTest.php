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
            ->set('statusFilter', 'pending_activation')
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
});
