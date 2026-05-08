<?php

use App\Livewire\Auth\Activate;
use App\Livewire\Invitations\PendingList;
use App\Livewire\Invitations\Send;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Services\InvitationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    config(['app.apex_domain' => 'skv1.test']);

    $this->seed(RolesAndPermissionsSeeder::class);

    $this->org = Organisation::factory()->create(['slug' => 'demo1']);

    app()->instance('currentOrganisation', $this->org);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);

    $this->actor = User::factory()->for($this->org)->create([
        'email' => 'admin@demo1.local',
        'password' => Hash::make('Password123!'),
        'status' => 'active',
    ]);
    $this->actor->assignRole('organisation_admin');
});

describe('Send invitation Livewire component', function () {
    it('queues the invitation when org_admin submits a valid email', function () {
        Mail::fake();
        $this->actingAs($this->actor);

        Livewire::test(Send::class)
            ->set('email', 'newhire@demo1.local')
            ->set('locale', 'nl')
            ->set('roles', ['organisation_admin'])
            ->call('send')
            ->assertHasNoErrors();

        Mail::assertQueued(InvitationMail::class);
        expect(Invitation::query()->whereHas('user', fn ($q) => $q->where('email', 'newhire@demo1.local'))->exists())->toBeTrue();
    });

    it('rejects invalid emails', function () {
        $this->actingAs($this->actor);

        Livewire::test(Send::class)
            ->set('email', 'not-an-email')
            ->call('send')
            ->assertHasErrors(['email']);
    });

    it('forbids users without invitations.send permission', function () {
        $this->actor->removeRole('organisation_admin');
        $this->actingAs($this->actor);

        Livewire::test(Send::class)
            ->set('email', 'x@demo1.local')
            ->call('send')
            ->assertStatus(403);
    });
});

describe('PendingList Livewire component', function () {
    it('lists only pending invitations belonging to the current organisation', function () {
        $other = Organisation::factory()->create(['slug' => 'demo2']);

        $localInv = app(InvitationService::class)->invite('local@demo1.local', 'nl', [], $this->actor);

        // simulate an invitation in another org by manually creating it
        app()->instance('currentOrganisation', $other);
        app(PermissionRegistrar::class)->setPermissionsTeamId($other->id);
        $otherActor = User::factory()->for($other)->create();
        app(InvitationService::class)->invite('other@demo2.local', 'nl', [], $otherActor);

        // back to demo1 context
        app()->instance('currentOrganisation', $this->org);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);

        $this->actingAs($this->actor);

        Livewire::test(PendingList::class)
            ->assertSee('local@demo1.local')
            ->assertDontSee('other@demo2.local');
    });

    it('cancels an invitation when the cancel button is invoked', function () {
        Mail::fake();

        $invitation = app(InvitationService::class)->invite('cancel@demo1.local', 'nl', [], $this->actor);

        $this->actingAs($this->actor);

        Livewire::test(PendingList::class)
            ->call('cancel', $invitation->id)
            ->assertHasNoErrors();

        expect(User::withTrashed()->find($invitation->user_id)->trashed())->toBeTrue();
    });

    it('resends a reminder when invoked', function () {
        Mail::fake();

        $invitation = app(InvitationService::class)->invite('remind@demo1.local', 'nl', [], $this->actor);

        $this->actingAs($this->actor);

        Livewire::test(PendingList::class)
            ->call('resend', $invitation->id)
            ->assertHasNoErrors();

        expect($invitation->fresh()->reminder_sent_at)->not->toBeNull();
        Mail::assertQueued(InvitationMail::class, 2);
    });
});

describe('Activate Livewire component (signed activation)', function () {
    it('renders the activation form on a valid signed URL', function () {
        Mail::fake();
        $invitation = app(InvitationService::class)->invite('activate@demo1.local', 'nl', [], $this->actor);

        $url = URL::temporarySignedRoute('invitation.accept', $invitation->expires_at, ['token' => $invitation->token]);

        $this->get($url)
            ->assertOk()
            ->assertSeeLivewire(Activate::class)
            ->assertSee('activate@demo1.local');
    });

    it('activates the user and redirects to dashboard on submit', function () {
        Mail::fake();
        $invitation = app(InvitationService::class)->invite('go@demo1.local', 'nl', [], $this->actor);

        Livewire::test(Activate::class, ['token' => $invitation->token])
            ->set('password', 'BrandNew!Pwd2026')
            ->set('password_confirmation', 'BrandNew!Pwd2026')
            ->call('submit')
            ->assertRedirect(route('dashboard'));

        expect(auth()->check())->toBeTrue()
            ->and(auth()->user()->email)->toBe('go@demo1.local')
            ->and(auth()->user()->status)->toBe('active');
    });

    it('shows an error if invitation has already been accepted', function () {
        Mail::fake();
        $invitation = app(InvitationService::class)->invite('twice@demo1.local', 'nl', [], $this->actor);
        app(InvitationService::class)->accept($invitation->token, 'First!Pwd2026');

        Livewire::test(Activate::class, ['token' => $invitation->token])
            ->set('password', 'Second!Pwd2026')
            ->set('password_confirmation', 'Second!Pwd2026')
            ->call('submit')
            ->assertHasErrors('token');
    });

    it('rejects mismatched password confirmation', function () {
        Mail::fake();
        $invitation = app(InvitationService::class)->invite('mismatch@demo1.local', 'nl', [], $this->actor);

        Livewire::test(Activate::class, ['token' => $invitation->token])
            ->set('password', 'Aaa!Pwd2026')
            ->set('password_confirmation', 'Bbb!Pwd2026')
            ->call('submit')
            ->assertHasErrors('password');
    });

    it('rejects unsigned URL access to the activation route', function () {
        Mail::fake();
        $invitation = app(InvitationService::class)->invite('unsigned@demo1.local', 'nl', [], $this->actor);

        $this->get('/invitations/'.$invitation->token.'/accept')
            ->assertStatus(403);
    });
});
