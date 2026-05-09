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
        firstName: 'newhire',
        middleName: null,
        lastName: '(test)',
        email: 'newhire@demo1.local',
        locale: 'nl',
        roles: ['organisation_admin'],
        invitedBy: $this->actor,
        organisationId: $this->org->id,
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

    $invitation = app(InvitationService::class)->invite(
        firstName: 'x',
        middleName: null,
        lastName: '(test)',
        email: 'x@demo1.local',
        locale: 'nl',
        roles: [],
        invitedBy: $this->actor,
        organisationId: $this->org->id,
    );

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

    $invitation = app(InvitationService::class)->invite(
        firstName: 'y',
        middleName: null,
        lastName: '(test)',
        email: 'y@demo1.local',
        locale: 'nl',
        roles: [],
        invitedBy: $this->actor,
        organisationId: $this->org->id,
    );
    $invitation->update(['expires_at' => now()->subHour()]);

    expect(fn () => app(InvitationService::class)->accept($invitation->token, 'Pwd!Strong2026'))
        ->toThrow(InvitationExpired::class);
});

it('rejects an already accepted invitation', function () {
    Mail::fake();

    $invitation = app(InvitationService::class)->invite(
        firstName: 'z',
        middleName: null,
        lastName: '(test)',
        email: 'z@demo1.local',
        locale: 'nl',
        roles: [],
        invitedBy: $this->actor,
        organisationId: $this->org->id,
    );
    app(InvitationService::class)->accept($invitation->token, 'Pwd!Strong2026');

    expect(fn () => app(InvitationService::class)->accept($invitation->token, 'Other!Pwd2026'))
        ->toThrow(InvitationAlreadyAccepted::class);
});

it('rejects accepting a cancelled (soft-deleted user) invitation', function () {
    Mail::fake();

    $invitation = app(InvitationService::class)->invite(
        firstName: 'cancel',
        middleName: null,
        lastName: '(test)',
        email: 'cancel@demo1.local',
        locale: 'nl',
        roles: [],
        invitedBy: $this->actor,
        organisationId: $this->org->id,
    );
    app(InvitationService::class)->cancel($invitation->fresh(), $this->actor);

    expect(fn () => app(InvitationService::class)->accept($invitation->token, 'Pwd!Strong2026'))
        ->toThrow(InvitationCancelled::class);
});

it('stores the optional 2FA secret on accept', function () {
    Mail::fake();

    $invitation = app(InvitationService::class)->invite(
        firstName: 'twofa',
        middleName: null,
        lastName: '(test)',
        email: 'twofa@demo1.local',
        locale: 'nl',
        roles: [],
        invitedBy: $this->actor,
        organisationId: $this->org->id,
    );

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

    $invitation = app(InvitationService::class)->invite(
        firstName: 'remind',
        middleName: null,
        lastName: '(test)',
        email: 'remind@demo1.local',
        locale: 'nl',
        roles: [],
        invitedBy: $this->actor,
        organisationId: $this->org->id,
    );
    expect($invitation->reminder_sent_at)->toBeNull();

    app(InvitationService::class)->resendReminder($invitation->fresh(), $this->actor);

    expect($invitation->fresh()->reminder_sent_at)->not->toBeNull();
    Mail::assertQueued(InvitationMail::class, 2);
});

it('cannot resend reminder for an accepted invitation', function () {
    Mail::fake();

    $invitation = app(InvitationService::class)->invite(
        firstName: 'done',
        middleName: null,
        lastName: '(test)',
        email: 'done@demo1.local',
        locale: 'nl',
        roles: [],
        invitedBy: $this->actor,
        organisationId: $this->org->id,
    );
    app(InvitationService::class)->accept($invitation->token, 'Pwd!Strong2026');

    expect(fn () => app(InvitationService::class)->resendReminder($invitation->fresh(), $this->actor))
        ->toThrow(InvitationAlreadyAccepted::class);
});

it('cancels an invitation and soft-deletes the pending user', function () {
    Mail::fake();

    $invitation = app(InvitationService::class)->invite(
        firstName: 'drop',
        middleName: null,
        lastName: '(test)',
        email: 'drop@demo1.local',
        locale: 'nl',
        roles: [],
        invitedBy: $this->actor,
        organisationId: $this->org->id,
    );
    $userId = $invitation->user_id;

    app(InvitationService::class)->cancel($invitation->fresh(), $this->actor);

    expect(User::withTrashed()->find($userId)->trashed())->toBeTrue();
});

it('purgeExpired hard-deletes invitations past expiry that are not accepted', function () {
    Mail::fake();

    $kept = app(InvitationService::class)->invite(
        firstName: 'kept',
        middleName: null,
        lastName: '(test)',
        email: 'kept@demo1.local',
        locale: 'nl',
        roles: [],
        invitedBy: $this->actor,
        organisationId: $this->org->id,
    );
    $expired = app(InvitationService::class)->invite(
        firstName: 'old',
        middleName: null,
        lastName: '(test)',
        email: 'old@demo1.local',
        locale: 'nl',
        roles: [],
        invitedBy: $this->actor,
        organisationId: $this->org->id,
    );
    $accepted = app(InvitationService::class)->invite(
        firstName: 'done',
        middleName: null,
        lastName: '(test)',
        email: 'done@demo1.local',
        locale: 'nl',
        roles: [],
        invitedBy: $this->actor,
        organisationId: $this->org->id,
    );

    $expired->update(['expires_at' => now()->subDays(1)]);
    app(InvitationService::class)->accept($accepted->token, 'Pwd!Strong2026');
    $accepted->update(['expires_at' => now()->subDays(1)]); // expired but accepted → keep

    $purged = app(InvitationService::class)->purgeExpired();

    expect($purged)->toBe(1)
        ->and(Invitation::find($expired->id))->toBeNull()
        ->and(Invitation::find($kept->id))->not->toBeNull()
        ->and(Invitation::find($accepted->id))->not->toBeNull();
});

it('persists provided name fields on invite', function () {
    Mail::fake();

    $invitation = app(InvitationService::class)->invite(
        firstName: 'Jan',
        middleName: 'van der',
        lastName: 'Berg',
        email: 'jvdb@demo1.local',
        locale: 'nl',
        roles: [],
        invitedBy: $this->actor,
        organisationId: $this->org->id,
    );

    expect($invitation->user->first_name)->toBe('Jan')
        ->and($invitation->user->middle_name)->toBe('van der')
        ->and($invitation->user->last_name)->toBe('Berg')
        ->and($invitation->user->email)->toBe('jvdb@demo1.local')
        ->and($invitation->user->organisation_id)->toBe($this->org->id);
});

it('persists explicit organisation_id and bypasses tenant trait', function () {
    Mail::fake();

    $otherOrg = Organisation::factory()->create(['slug' => 'demo-other']);

    // beforeEach binds currentOrganisation = demo1, but we pass demo-other's id explicitly
    $invitation = app(InvitationService::class)->invite(
        firstName: 'Cross',
        middleName: null,
        lastName: 'Org',
        email: 'cross@demo-other.local',
        locale: 'nl',
        roles: [],
        invitedBy: $this->actor,
        organisationId: $otherOrg->id,
    );

    expect($invitation->user->organisation_id)->toBe($otherOrg->id)
        ->and($invitation->user->organisation_id)->not->toBe($this->org->id);
});

it('propagates super_admin role across all organisations on invite', function () {
    Mail::fake();

    $otherOrg = Organisation::factory()->create(['slug' => 'demo-other']);
    $thirdOrg = Organisation::factory()->create(['slug' => 'demo-third']);

    $invitation = app(InvitationService::class)->invite(
        firstName: 'Cross',
        middleName: null,
        lastName: 'Admin',
        email: 'cross-admin@demo1.local',
        locale: 'nl',
        roles: ['super_admin'],
        invitedBy: $this->actor,
        organisationId: $this->org->id,
    );

    $invitee = $invitation->user;

    foreach ([$this->org, $otherOrg, $thirdOrg] as $org) {
        app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
        expect($invitee->fresh()->hasRole('super_admin'))
            ->toBeTrue("expected super_admin role in org {$org->slug}");
    }
});

it('only assigns non-super_admin roles in the invitee organisation', function () {
    Mail::fake();

    $otherOrg = Organisation::factory()->create(['slug' => 'demo-elsewhere']);

    $invitation = app(InvitationService::class)->invite(
        firstName: 'Org',
        middleName: null,
        lastName: 'Member',
        email: 'org-member@demo1.local',
        locale: 'nl',
        roles: ['organisation_admin'],
        invitedBy: $this->actor,
        organisationId: $this->org->id,
    );

    $invitee = $invitation->user;

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
    expect($invitee->fresh()->hasRole('organisation_admin'))->toBeTrue();

    app(PermissionRegistrar::class)->setPermissionsTeamId($otherOrg->id);
    expect($invitee->fresh()->hasRole('organisation_admin'))->toBeFalse();
});
