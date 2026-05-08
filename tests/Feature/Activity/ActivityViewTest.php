<?php

use App\Livewire\Activity\Index;
use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->orgA = Organisation::factory()->create(['slug' => 'a']);
    $this->orgB = Organisation::factory()->create(['slug' => 'b']);

    app()->instance('currentOrganisation', $this->orgA);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->orgA->id);

    $this->actor = User::factory()->for($this->orgA)->create(['email' => 'admin@a.local']);
    $this->actor->assignRole('organisation_admin');

    $this->otherActor = User::factory()->for($this->orgB)->create(['email' => 'admin@b.local']);

    $this->subjectA = User::factory()->for($this->orgA)->create(['email' => 'sub@a.local']);
    $this->subjectB = User::factory()->for($this->orgB)->create(['email' => 'sub@b.local']);

    activity('invitations')->causedBy($this->actor)->performedOn($this->subjectA)->withProperties(['event' => 'sent'])->log('sent');
    activity('invitations')->causedBy($this->otherActor)->performedOn($this->subjectB)->log('sent');
    activity('users')->causedBy($this->actor)->performedOn($this->subjectA)->log('updated');
});

it('forbids users without activity.view permission', function () {
    $regular = User::factory()->for($this->orgA)->create();
    $this->actingAs($regular);

    Livewire::test(Index::class)->assertStatus(403);
});

it('shows activities for the current organisation only (org_admin)', function () {
    $this->actingAs($this->actor);

    Livewire::test(Index::class)
        ->assertSee('admin@a.local')
        ->assertDontSee('admin@b.local');
});

it('shows all activities for super_admin', function () {
    $super = User::factory()->superAdmin()->create(['organisation_id' => null]);
    $this->actingAs($super);

    Livewire::test(Index::class)
        ->assertSee('admin@a.local')
        ->assertSee('admin@b.local');
});

it('filters by log_name', function () {
    $this->actingAs($this->actor);

    Livewire::test(Index::class)
        ->set('logFilter', 'users')
        ->assertSee('updated')
        ->assertDontSee('sent');
});

it('filters by causer (actor)', function () {
    $secondActor = User::factory()->for($this->orgA)->create(['email' => 'second@a.local']);
    activity('invitations')->causedBy($secondActor)->performedOn($this->subjectA)->log('sent-by-second');

    $this->actingAs($this->actor);

    $component = Livewire::test(Index::class)
        ->set('actorFilter', $secondActor->id);

    $rows = $component->viewData('activities');
    expect($rows->total())->toBe(1)
        ->and($rows->first()->description)->toBe('sent-by-second');
});

it('paginates results at 20 per page', function () {
    for ($i = 0; $i < 25; $i++) {
        activity('test')->causedBy($this->actor)->performedOn($this->subjectA)->log("event-{$i}");
    }

    $this->actingAs($this->actor);

    $component = Livewire::test(Index::class);
    expect($component->viewData('activities')->count())->toBeLessThanOrEqual(20);
});
