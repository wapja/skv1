<?php

use App\Exceptions\Invitation\InvitationAlreadyAccepted;
use App\Exceptions\Invitation\InvitationCancelled;
use App\Exceptions\Invitation\InvitationExpired;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;
use App\Services\InvitationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->org = Organisation::factory()->create(['slug' => 'demo1']);

    Role::firstOrCreate([
        'name' => 'organisation_admin',
        'guard_name' => 'web',
        'team_id' => $this->org->id,
    ]);

    app()->instance('currentOrganisation', $this->org);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);

    $this->actor = User::factory()->for($this->org)->create();
    $this->actor->assignRole('organisation_admin');
});

it('creates a pending user and an invitation row, queues the mail', function () {
    Mail::fake();

    $invitation = app(InvitationService::class)->invite(
        'newhire@demo1.local',
        'nl',
        ['organisation_admin'],
        $this->actor,
    );

    expect($invitation)->toBeInstanceOf(Invitation::class)
        ->and($invitation->user->email)->toBe('newhire@demo1.local')
        ->and($invitation->user->status)->toBe('pending_activation')
        ->and($invitation->user->organisation_id)->toBe($this->org->id)
        ->and($invitation->user->locale)->toBe('nl')
        ->and($invitation->token)->toHaveLength(64)
        ->and($invitation->expires_at->isAfter(now()->addDays(6)))->toBeTrue()
        ->and($invitation->invited_by)->toBe($this->actor->id);

    expect($invitation->user->hasRole('organisation_admin'))->toBeTrue();

    Mail::assertQueued(InvitationMail::class, function (InvitationMail $mail) {
        return $mail->hasTo('newhire@demo1.local');
    });
});

it('activates the user and consumes the invitation', function () {
    Mail::fake();

    $invitation = app(InvitationService::class)->invite('x@demo1.local', 'nl', [], $this->actor);

    $user = app(InvitationService::class)->accept(
        $invitation->token,
        'NewSecure!Pwd2026'
    );

    expect($user->status)->toBe('active')
        ->and($user->activated_at)->not->toBeNull()
        ->and($user->password)->not->toBeNull()
        ->and(Hash::check('NewSecure!Pwd2026', $user->password))->toBeTrue();

    expect($invitation->fresh()->accepted_at)->not->toBeNull();
});

it('rejects an expired invitation token', function () {
    Mail::fake();

    $invitation = app(InvitationService::class)->invite('y@demo1.local', 'nl', [], $this->actor);
    $invitation->update(['expires_at' => now()->subHour()]);

    expect(fn () => app(InvitationService::class)->accept($invitation->token, 'Pwd!Strong2026'))
        ->toThrow(InvitationExpired::class);
});

it('rejects an already accepted invitation', function () {
    Mail::fake();

    $invitation = app(InvitationService::class)->invite('z@demo1.local', 'nl', [], $this->actor);
    app(InvitationService::class)->accept($invitation->token, 'Pwd!Strong2026');

    expect(fn () => app(InvitationService::class)->accept($invitation->token, 'Other!Pwd2026'))
        ->toThrow(InvitationAlreadyAccepted::class);
});

it('rejects accepting a cancelled (soft-deleted user) invitation', function () {
    Mail::fake();

    $invitation = app(InvitationService::class)->invite('cancel@demo1.local', 'nl', [], $this->actor);
    app(InvitationService::class)->cancel($invitation->fresh(), $this->actor);

    expect(fn () => app(InvitationService::class)->accept($invitation->token, 'Pwd!Strong2026'))
        ->toThrow(InvitationCancelled::class);
});

it('stores the optional 2FA secret on accept', function () {
    Mail::fake();

    $invitation = app(InvitationService::class)->invite('twofa@demo1.local', 'nl', [], $this->actor);

    $user = app(InvitationService::class)->accept(
        $invitation->token,
        'Pwd!Strong2026',
        'JBSWY3DPEHPK3PXP'
    );

    expect($user->two_factor_secret)->not->toBeNull()
        ->and($user->two_factor_enabled_at)->not->toBeNull();
});

it('resends a reminder and bumps reminder_sent_at', function () {
    Mail::fake();

    $invitation = app(InvitationService::class)->invite('remind@demo1.local', 'nl', [], $this->actor);
    expect($invitation->reminder_sent_at)->toBeNull();

    app(InvitationService::class)->resendReminder($invitation->fresh(), $this->actor);

    expect($invitation->fresh()->reminder_sent_at)->not->toBeNull();
    Mail::assertQueued(InvitationMail::class, 2);
});

it('cannot resend reminder for an accepted invitation', function () {
    Mail::fake();

    $invitation = app(InvitationService::class)->invite('done@demo1.local', 'nl', [], $this->actor);
    app(InvitationService::class)->accept($invitation->token, 'Pwd!Strong2026');

    expect(fn () => app(InvitationService::class)->resendReminder($invitation->fresh(), $this->actor))
        ->toThrow(InvitationAlreadyAccepted::class);
});

it('cancels an invitation and soft-deletes the pending user', function () {
    Mail::fake();

    $invitation = app(InvitationService::class)->invite('drop@demo1.local', 'nl', [], $this->actor);
    $userId = $invitation->user_id;

    app(InvitationService::class)->cancel($invitation->fresh(), $this->actor);

    expect(User::withTrashed()->find($userId)->trashed())->toBeTrue();
});

it('purgeExpired hard-deletes invitations past expiry that are not accepted', function () {
    Mail::fake();

    $kept = app(InvitationService::class)->invite('kept@demo1.local', 'nl', [], $this->actor);
    $expired = app(InvitationService::class)->invite('old@demo1.local', 'nl', [], $this->actor);
    $accepted = app(InvitationService::class)->invite('done@demo1.local', 'nl', [], $this->actor);

    $expired->update(['expires_at' => now()->subDays(1)]);
    app(InvitationService::class)->accept($accepted->token, 'Pwd!Strong2026');
    $accepted->update(['expires_at' => now()->subDays(1)]); // expired but accepted → keep

    $purged = app(InvitationService::class)->purgeExpired();

    expect($purged)->toBe(1)
        ->and(Invitation::find($expired->id))->toBeNull()
        ->and(Invitation::find($kept->id))->not->toBeNull()
        ->and(Invitation::find($accepted->id))->not->toBeNull();
});
