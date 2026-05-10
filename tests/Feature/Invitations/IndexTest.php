<?php

use App\Livewire\Invitations\Index;
use App\Models\Invitation;
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

describe('Invitations Index Livewire', function () {
    it('defaults to created_at desc when no sort is selected', function () {
        $invited1 = User::factory()->for($this->org)->create();
        $invited2 = User::factory()->for($this->org)->create();
        Invitation::factory()->create(['user_id' => $invited1->id, 'invited_by' => $this->actor->id, 'created_at' => now()->subDays(2)]);
        Invitation::factory()->create(['user_id' => $invited2->id, 'invited_by' => $this->actor->id, 'created_at' => now()->subHour()]);

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->assertSet('sortColumn', null)
            ->assertViewHas('invitations', fn ($invs) => $invs->getCollection()->first()->user_id === $invited2->id);
    });

    it('sorts by sent_at asc, then desc, then back to default', function () {
        $invited1 = User::factory()->for($this->org)->create();
        Invitation::factory()->create(['user_id' => $invited1->id, 'invited_by' => $this->actor->id]);

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->call('sort', 'sent_at')
            ->assertSet('sortColumn', 'sent_at')->assertSet('sortDirection', 'asc')
            ->call('sort', 'sent_at')
            ->assertSet('sortColumn', 'sent_at')->assertSet('sortDirection', 'desc')
            ->call('sort', 'sent_at')
            ->assertSet('sortColumn', null);
    });

    it('clamps an unknown sortColumn back to null', function () {
        $this->actingAs($this->actor);
        Livewire::test(Index::class)
            ->set('sortColumn', 'bogus_field')
            ->assertSet('sortColumn', null);
    });

    it('forbids users without invitations.send permission', function () {
        $regular = User::factory()->for($this->org)->create();
        $this->actingAs($regular);
        Livewire::test(Index::class)->assertStatus(403);
    });
});
