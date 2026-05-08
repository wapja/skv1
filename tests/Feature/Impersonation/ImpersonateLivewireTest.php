<?php

use App\Livewire\Users\Impersonate;
use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->org = Organisation::factory()->create(['slug' => 'demo1']);
    app()->instance('currentOrganisation', $this->org);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);

    $this->actor = User::factory()->for($this->org)->create();
    $this->actor->assignRole('organisation_admin');

    $this->target = User::factory()->for($this->org)->create(['email' => 'target@demo1.local']);
});

it('opens the modal when the open-impersonate event is received', function () {
    $this->actingAs($this->actor);

    Livewire::test(Impersonate::class)
        ->assertSet('open', false)
        ->dispatch('open-impersonate', userId: $this->target->id)
        ->assertSet('open', true)
        ->assertSet('targetUserId', $this->target->id);
});

it('starts impersonation and redirects to dashboard on success', function () {
    $this->actingAs($this->actor);

    Livewire::test(Impersonate::class)
        ->call('openFor', $this->target->id)
        ->set('reason', 'Investigating bug report')
        ->call('start')
        ->assertRedirect(route('dashboard'));

    expect(auth()->user()->id)->toBe($this->target->id)
        ->and(auth()->user()->isImpersonated())->toBeTrue();
});

it('shows an error when the reason is empty', function () {
    $this->actingAs($this->actor);

    Livewire::test(Impersonate::class)
        ->call('openFor', $this->target->id)
        ->set('reason', '')
        ->call('start')
        ->assertHasErrors('reason');
});

it('surfaces a permission error when target is in another org', function () {
    $other = Organisation::factory()->create(['slug' => 'demo2']);
    $foreignTarget = User::factory()->for($other)->create();

    $this->actingAs($this->actor);

    Livewire::test(Impersonate::class)
        ->call('openFor', $foreignTarget->id)
        ->set('reason', 'Trying to cross orgs')
        ->call('start')
        ->assertHasErrors('reason');
});
