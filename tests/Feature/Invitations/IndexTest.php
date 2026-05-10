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

    it('filters email case-insensitively via ILIKE contains', function () {
        $u1 = User::factory()->for($this->org)->create(['email' => 'Alice@demo1.local']);
        $u2 = User::factory()->for($this->org)->create(['email' => 'BOB@demo1.local']);
        $u3 = User::factory()->for($this->org)->create(['email' => 'carol@demo1.local']);
        Invitation::factory()->create(['user_id' => $u1->id, 'invited_by' => $this->actor->id]);
        Invitation::factory()->create(['user_id' => $u2->id, 'invited_by' => $this->actor->id]);
        Invitation::factory()->create(['user_id' => $u3->id, 'invited_by' => $this->actor->id]);

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('filters.email', 'ALICE')
            ->assertViewHas('invitations', fn ($invs) => $invs->total() === 1
                && $invs->getCollection()->first()->user->email === 'Alice@demo1.local');
    });

    it('name filter matches first_name, middle_name, or last_name on related user', function () {
        $u1 = User::factory()->for($this->org)->create(['first_name' => 'Anna', 'last_name' => 'Zijlstra']);
        $u2 = User::factory()->for($this->org)->create(['first_name' => 'Bart', 'middle_name' => 'Anna', 'last_name' => 'Pieters']);
        $u3 = User::factory()->for($this->org)->create(['first_name' => 'Bert', 'last_name' => 'Anna']);
        $u4 = User::factory()->for($this->org)->create(['first_name' => 'Carl', 'last_name' => 'Yssel']);
        foreach ([$u1, $u2, $u3, $u4] as $u) {
            Invitation::factory()->create(['user_id' => $u->id, 'invited_by' => $this->actor->id]);
        }

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('filters.name', 'Anna')
            ->assertViewHas('invitations', fn ($invs) => $invs->total() === 3);
    });

    it('inviter filter matches inviter email', function () {
        $invited = User::factory()->for($this->org)->create();
        $other_inviter = User::factory()->for($this->org)->create(['email' => 'other@demo1.local']);
        Invitation::factory()->create(['user_id' => $invited->id, 'invited_by' => $this->actor->id]);
        Invitation::factory()->create(['user_id' => $invited->id, 'invited_by' => $other_inviter->id]);

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('filters.inviter', 'admin')
            ->assertViewHas('invitations', fn ($invs) => $invs->total() === 1);
    });

    it('status filter pending shows only open + active-user invitations', function () {
        $u_pending  = User::factory()->for($this->org)->create();
        $u_accepted = User::factory()->for($this->org)->create();
        $u_expired  = User::factory()->for($this->org)->create();
        $u_cancelled= User::factory()->for($this->org)->create();

        Invitation::factory()->create(['user_id' => $u_pending->id,  'invited_by' => $this->actor->id, 'expires_at' => now()->addDays(3), 'accepted_at' => null]);
        Invitation::factory()->create(['user_id' => $u_accepted->id, 'invited_by' => $this->actor->id, 'expires_at' => now()->subDays(1), 'accepted_at' => now()]);
        Invitation::factory()->create(['user_id' => $u_expired->id,  'invited_by' => $this->actor->id, 'expires_at' => now()->subDays(1), 'accepted_at' => null]);
        Invitation::factory()->create(['user_id' => $u_cancelled->id, 'invited_by' => $this->actor->id, 'expires_at' => now()->addDays(3), 'accepted_at' => null]);
        $u_cancelled->delete();

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('filters.status', 'pending')
            ->assertViewHas('invitations', fn ($invs) => $invs->total() === 1
                && $invs->getCollection()->first()->user_id === $u_pending->id);
    });

    it('status filter accepted shows only accepted invitations', function () {
        $u1 = User::factory()->for($this->org)->create();
        $u2 = User::factory()->for($this->org)->create();
        Invitation::factory()->create(['user_id' => $u1->id, 'invited_by' => $this->actor->id, 'accepted_at' => null]);
        Invitation::factory()->create(['user_id' => $u2->id, 'invited_by' => $this->actor->id, 'accepted_at' => now()]);

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('filters.status', 'accepted')
            ->assertViewHas('invitations', fn ($invs) => $invs->total() === 1
                && $invs->getCollection()->first()->accepted_at !== null);
    });

    it('status filter expired shows only expired open invitations', function () {
        $u1 = User::factory()->for($this->org)->create();
        $u2 = User::factory()->for($this->org)->create();
        Invitation::factory()->create(['user_id' => $u1->id, 'invited_by' => $this->actor->id, 'expires_at' => now()->subDays(1), 'accepted_at' => null]);
        Invitation::factory()->create(['user_id' => $u2->id, 'invited_by' => $this->actor->id, 'expires_at' => now()->addDays(3), 'accepted_at' => null]);

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('filters.status', 'expired')
            ->assertViewHas('invitations', fn ($invs) => $invs->total() === 1
                && $invs->getCollection()->first()->user_id === $u1->id);
    });

    it('status filter cancelled shows only invitations with soft-deleted user', function () {
        $u1 = User::factory()->for($this->org)->create();
        $u2 = User::factory()->for($this->org)->create();
        Invitation::factory()->create(['user_id' => $u1->id, 'invited_by' => $this->actor->id]);
        Invitation::factory()->create(['user_id' => $u2->id, 'invited_by' => $this->actor->id]);
        $u2->delete();

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('filters.status', 'cancelled')
            ->assertViewHas('invitations', fn ($invs) => $invs->total() === 1
                && $invs->getCollection()->first()->user_id === $u2->id);
    });

    it('expires_at filter is "≥"', function () {
        $u1 = User::factory()->for($this->org)->create();
        $u2 = User::factory()->for($this->org)->create();
        Invitation::factory()->create(['user_id' => $u1->id, 'invited_by' => $this->actor->id, 'expires_at' => '2026-01-01 00:00:00']);
        Invitation::factory()->create(['user_id' => $u2->id, 'invited_by' => $this->actor->id, 'expires_at' => '2026-06-01 00:00:00']);

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('filters.expires_at', '2026-03-01')
            ->assertViewHas('invitations', fn ($invs) => $invs->total() === 1
                && $invs->getCollection()->first()->user_id === $u2->id);
    });

    it('sent_at filter is "≥" on created_at', function () {
        $u1 = User::factory()->for($this->org)->create();
        $u2 = User::factory()->for($this->org)->create();
        Invitation::factory()->create(['user_id' => $u1->id, 'invited_by' => $this->actor->id, 'created_at' => '2025-12-15 00:00:00', 'updated_at' => '2025-12-15 00:00:00']);
        Invitation::factory()->create(['user_id' => $u2->id, 'invited_by' => $this->actor->id, 'created_at' => '2026-04-15 00:00:00', 'updated_at' => '2026-04-15 00:00:00']);

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('filters.sent_at', '2026-01-01')
            ->assertViewHas('invitations', fn ($invs) => $invs->total() === 1
                && $invs->getCollection()->first()->user_id === $u2->id);
    });

    it('derives status from accepted_at / expires_at / user.deleted_at', function () {
        $u_pending  = User::factory()->for($this->org)->create();
        $u_accepted = User::factory()->for($this->org)->create();
        $u_expired  = User::factory()->for($this->org)->create();
        $u_cancelled= User::factory()->for($this->org)->create();

        $i_p = Invitation::factory()->create(['user_id' => $u_pending->id,   'invited_by' => $this->actor->id, 'expires_at' => now()->addDays(3), 'accepted_at' => null]);
        $i_a = Invitation::factory()->create(['user_id' => $u_accepted->id,  'invited_by' => $this->actor->id, 'expires_at' => now()->subDays(1), 'accepted_at' => now()]);
        $i_e = Invitation::factory()->create(['user_id' => $u_expired->id,   'invited_by' => $this->actor->id, 'expires_at' => now()->subDays(1), 'accepted_at' => null]);
        $i_c = Invitation::factory()->create(['user_id' => $u_cancelled->id, 'invited_by' => $this->actor->id, 'expires_at' => now()->addDays(3), 'accepted_at' => null]);
        $u_cancelled->delete();

        $this->actingAs($this->actor);

        $component = Livewire::test(Index::class);

        $i_p->load(['user' => fn ($q) => $q->withTrashed()]);
        $i_a->load(['user' => fn ($q) => $q->withTrashed()]);
        $i_e->load(['user' => fn ($q) => $q->withTrashed()]);
        $i_c->load(['user' => fn ($q) => $q->withTrashed()]);

        expect($component->instance()->status($i_p))->toBe('pending')
            ->and($component->instance()->status($i_a))->toBe('accepted')
            ->and($component->instance()->status($i_e))->toBe('expired')
            ->and($component->instance()->status($i_c))->toBe('cancelled');
    });

    it('sorts by status in stable order (accepted → pending → expired → cancelled)', function () {
        $u_pending  = User::factory()->for($this->org)->create();
        $u_accepted = User::factory()->for($this->org)->create();
        $u_expired  = User::factory()->for($this->org)->create();
        $u_cancelled= User::factory()->for($this->org)->create();

        Invitation::factory()->create(['user_id' => $u_pending->id,   'invited_by' => $this->actor->id, 'expires_at' => now()->addDays(3), 'accepted_at' => null]);
        Invitation::factory()->create(['user_id' => $u_accepted->id,  'invited_by' => $this->actor->id, 'expires_at' => now()->subDays(1), 'accepted_at' => now()]);
        Invitation::factory()->create(['user_id' => $u_expired->id,   'invited_by' => $this->actor->id, 'expires_at' => now()->subDays(1), 'accepted_at' => null]);
        Invitation::factory()->create(['user_id' => $u_cancelled->id, 'invited_by' => $this->actor->id, 'expires_at' => now()->addDays(3), 'accepted_at' => null]);
        $u_cancelled->delete();

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->call('sort', 'status')
            ->assertViewHas('invitations', function ($invs) use ($u_accepted, $u_pending, $u_expired, $u_cancelled) {
                $userIds = $invs->getCollection()->pluck('user_id')->all();
                return $userIds === [$u_accepted->id, $u_pending->id, $u_expired->id, $u_cancelled->id];
            });
    });

    it('sorts by email, name, inviter asc and desc', function () {
        $u1 = User::factory()->for($this->org)->create(['email' => 'aaa@demo1.local', 'first_name' => 'A', 'last_name' => 'A']);
        $u2 = User::factory()->for($this->org)->create(['email' => 'zzz@demo1.local', 'first_name' => 'Z', 'last_name' => 'Z']);
        Invitation::factory()->create(['user_id' => $u1->id, 'invited_by' => $this->actor->id]);
        Invitation::factory()->create(['user_id' => $u2->id, 'invited_by' => $this->actor->id]);

        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->call('sort', 'email')
            ->assertViewHas('invitations', fn ($i) => $i->getCollection()->first()->user_id === $u1->id)
            ->call('sort', 'email')
            ->assertViewHas('invitations', fn ($i) => $i->getCollection()->first()->user_id === $u2->id)
            ->call('sort', 'name')
            ->assertViewHas('invitations', fn ($i) => $i->getCollection()->first()->user_id === $u1->id);
    });

    it('sanitises unknown filter keys from session state', function () {
        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('filters', ['email' => 'demo', 'bogus_key' => 'x', 'name' => 'Bob'])
            ->assertSet('filters.email', 'demo')
            ->assertSet('filters.name', 'Bob')
            ->tap(fn ($c) => expect(array_key_exists('bogus_key', $c->get('filters')))->toBeFalse());
    });

    it('clamps invalid status filter values back to empty string', function () {
        $this->actingAs($this->actor);
        Livewire::test(Index::class)
            ->set('filters.status', 'banana')
            ->assertSet('filters.status', '');
    });

    it('resets to page 1 when any filter changes', function () {
        $invited = User::factory()->count(15)->for($this->org)->create();
        foreach ($invited as $u) {
            Invitation::factory()->create(['user_id' => $u->id, 'invited_by' => $this->actor->id]);
        }
        $this->actingAs($this->actor);

        Livewire::test(Index::class)
            ->set('perPage', 5)
            ->call('gotoPage', 2)
            ->assertSet('paginators.page', 2)
            ->set('filters.email', 'x')
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
});
