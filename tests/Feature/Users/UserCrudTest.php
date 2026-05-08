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
        'name' => 'Admin Een',
        'email' => 'admin@demo1.local',
    ]);
    $this->actor->assignRole('organisation_admin');
});

describe('Users Index Livewire', function () {
    it('lists users in the current organisation', function () {
        $other = User::factory()->for($this->org)->create(['name' => 'Bob', 'email' => 'bob@demo1.local']);

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->assertSee('Bob')
            ->assertSee('admin@demo1.local');
    });

    it('hides users from other organisations', function () {
        $other = Organisation::factory()->create(['slug' => 'demo2']);
        User::factory()->for($other)->create(['name' => 'Outsider', 'email' => 'out@demo2.local']);

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->assertDontSee('Outsider');
    });

    it('filters users by status', function () {
        User::factory()->for($this->org)->create(['name' => 'Active Alice', 'status' => 'active']);
        User::factory()->for($this->org)->pendingActivation()->create(['name' => 'Pending Pete']);
        User::factory()->for($this->org)->disabled()->create(['name' => 'Disabled Dan']);

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('statusFilter', 'pending_activation')
            ->assertSee('Pending Pete')
            ->assertDontSee('Active Alice')
            ->assertDontSee('Disabled Dan');
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
            'name' => 'Old Name',
            'email' => 'old@demo1.local',
            'status' => 'active',
            'locale' => 'nl',
        ]);

        $this->actingAs($this->actor);

        Livewire::test(Edit::class, ['user' => $target])
            ->set('name', 'New Name')
            ->set('email', 'new@demo1.local')
            ->set('status', 'disabled')
            ->set('locale', 'en')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('users.index'));

        $target->refresh();
        expect($target->name)->toBe('New Name')
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
