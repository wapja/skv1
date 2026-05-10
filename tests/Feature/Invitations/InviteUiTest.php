<?php

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
    config(['app.apex_domain' => 'skv1.test', 'app.url' => 'https://skv1.test']);
    URL::forceRootUrl('https://skv1.test');

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

        Livewire::test('invitations.send')
            ->set('firstName', 'New')
            ->set('lastName', 'Hire')
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

        Livewire::test('invitations.send')
            ->set('firstName', 'A')
            ->set('lastName', 'B')
            ->set('email', 'not-an-email')
            ->call('send')
            ->assertHasErrors(['email']);
    });

    it('forbids users without invitations.send permission', function () {
        $this->actor->removeRole('organisation_admin');
        $this->actingAs($this->actor);

        Livewire::test('invitations.send')
            ->set('firstName', 'X')
            ->set('lastName', 'Y')
            ->set('email', 'x@demo1.local')
            ->call('send')
            ->assertStatus(403);
    });

    it('requires first_name and last_name', function () {
        $this->actingAs($this->actor);

        Livewire::test('invitations.send')
            ->set('firstName', '')
            ->set('lastName', '')
            ->set('email', 'someone@demo1.local')
            ->call('send')
            ->assertHasErrors(['firstName', 'lastName']);
    });

    it('accepts an empty middle_name', function () {
        Mail::fake();
        $this->actingAs($this->actor);

        Livewire::test('invitations.send')
            ->set('firstName', 'Solo')
            ->set('middleName', '')
            ->set('lastName', 'Name')
            ->set('email', 'solo@demo1.local')
            ->call('send')
            ->assertHasNoErrors();

        $created = User::where('email', 'solo@demo1.local')->first();
        expect($created)->not->toBeNull()
            ->and($created->middle_name)->toBeNull();
    });

    it('auto-fills organisation from tenant context', function () {
        Mail::fake();
        $this->actingAs($this->actor);

        Livewire::test('invitations.send')
            ->set('firstName', 'Auto')
            ->set('lastName', 'Tenant')
            ->set('email', 'auto@demo1.local')
            ->call('send')
            ->assertHasNoErrors();

        $created = User::where('email', 'auto@demo1.local')->first();
        expect($created->organisation_id)->toBe($this->org->id);
    });

    it('ignores spoofed organisationId from tenant context', function () {
        Mail::fake();
        $other = Organisation::factory()->create(['slug' => 'demo-spoof']);
        $this->actingAs($this->actor);

        Livewire::test('invitations.send')
            ->set('firstName', 'Spoof')
            ->set('lastName', 'Attempt')
            ->set('email', 'spoof@demo1.local')
            ->set('organisationId', $other->id)
            ->call('send')
            ->assertHasNoErrors();

        $created = User::where('email', 'spoof@demo1.local')->first();
        expect($created->organisation_id)->toBe($this->org->id)
            ->and($created->organisation_id)->not->toBe($other->id);
    });

    it('super-admin on apex can invite into chosen org', function () {
        Mail::fake();

        $target = Organisation::factory()->create(['slug' => 'apex-target']);

        // Drop tenant context (simulate apex host)
        app()->forgetInstance('currentOrganisation');

        $superAdmin = User::factory()->superAdmin()->create([
            'email' => 'super@example.local',
            'password' => Hash::make('Password123!'),
            'status' => 'active',
            'organisation_id' => null,
        ]);

        $this->actingAs($superAdmin);

        Livewire::test('invitations.send')
            ->set('firstName', 'Apex')
            ->set('lastName', 'Invite')
            ->set('email', 'apex@apex-target.local')
            ->set('organisationId', $target->id)
            ->call('send')
            ->assertHasNoErrors();

        $created = User::withoutTenantScope()->where('email', 'apex@apex-target.local')->first();
        expect($created)->not->toBeNull()
            ->and($created->organisation_id)->toBe($target->id);
    });

    it('requires organisationId on apex (no tenant context)', function () {
        app()->forgetInstance('currentOrganisation');

        $superAdmin = User::factory()->superAdmin()->create([
            'email' => 'super2@example.local',
            'password' => Hash::make('Password123!'),
            'status' => 'active',
            'organisation_id' => null,
        ]);

        $this->actingAs($superAdmin);

        Livewire::test('invitations.send')
            ->set('firstName', 'Missing')
            ->set('lastName', 'Org')
            ->set('email', 'missing@apex.local')
            // no organisationId set
            ->call('send')
            ->assertHasErrors(['organisationId']);
    });

    it('rejects unknown organisationId on apex', function () {
        app()->forgetInstance('currentOrganisation');

        $superAdmin = User::factory()->superAdmin()->create([
            'email' => 'super3@example.local',
            'password' => Hash::make('Password123!'),
            'status' => 'active',
            'organisation_id' => null,
        ]);

        $this->actingAs($superAdmin);

        Livewire::test('invitations.send')
            ->set('firstName', 'Bogus')
            ->set('lastName', 'Org')
            ->set('email', 'bogus@apex.local')
            ->set('organisationId', 999999)
            ->call('send')
            ->assertHasErrors(['organisationId']);
    });

    it('forbids non-super-admin from inviting on apex', function () {
        // Regular admin actor from beforeEach. Drop tenant context (apex).
        app()->forgetInstance('currentOrganisation');

        $this->actingAs($this->actor);

        Livewire::test('invitations.send')
            ->set('firstName', 'Sneaky')
            ->set('lastName', 'Admin')
            ->set('email', 'sneaky@apex.local')
            ->set('organisationId', $this->org->id)
            ->call('send')
            ->assertStatus(403);
    });

    it('hides organisation dropdown from non-super-admin on apex', function () {
        app()->forgetInstance('currentOrganisation');
        $this->actingAs($this->actor);

        $component = Livewire::test('invitations.send');

        expect($component->instance()->availableOrganisations())->toBe([]);
    });

    it('shows organisation_admin / test1 / test2 to org-admin inviters', function () {
        $this->actingAs($this->actor);

        $component = Livewire::test('invitations.send');

        expect($component->instance()->availableRoles())
            ->toBe([
                'organisation_admin' => __('Organisatie-admin'),
                'test1' => __('Test rol 1'),
                'test2' => __('Test rol 2'),
            ]);
    });

    it('shows super_admin additionally to super-admin inviters on apex', function () {
        app()->forgetInstance('currentOrganisation');

        $superAdmin = User::factory()->superAdmin()->create([
            'email' => 'super-picker@example.local',
            'organisation_id' => null,
        ]);

        $this->actingAs($superAdmin);

        $component = Livewire::test('invitations.send');

        expect(array_keys($component->instance()->availableRoles()))
            ->toBe(['super_admin', 'organisation_admin', 'test1', 'test2']);
    });

    it('rejects a spoofed super_admin role from a non-super-admin inviter', function () {
        $this->actingAs($this->actor);

        Livewire::test('invitations.send')
            ->set('firstName', 'Spoof')
            ->set('lastName', 'Role')
            ->set('email', 'spoof-role@demo1.local')
            ->set('roles', ['super_admin'])
            ->call('send')
            ->assertHasErrors(['roles.0']);
    });
});

describe('Activate Livewire component (signed activation)', function () {
    it('renders the activation form on a valid signed URL', function () {
        Mail::fake();
        $invitation = app(InvitationService::class)->invite(
            firstName: 'Activate',
            middleName: null,
            lastName: 'User',
            email: 'activate@demo1.local',
            locale: 'nl',
            roles: [],
            invitedBy: $this->actor,
            organisationId: $this->org->id,
        );

        $url = URL::temporarySignedRoute('invitation.accept', $invitation->expires_at, ['token' => $invitation->token]);

        $this->get($url)
            ->assertOk()
            ->assertSeeLivewire('auth.activate')
            ->assertSee('activate@demo1.local');
    });

    it('activates the user and redirects to dashboard on submit', function () {
        Mail::fake();
        $invitation = app(InvitationService::class)->invite(
            firstName: 'Go',
            middleName: null,
            lastName: 'User',
            email: 'go@demo1.local',
            locale: 'nl',
            roles: [],
            invitedBy: $this->actor,
            organisationId: $this->org->id,
        );

        Livewire::test('auth.activate', ['token' => $invitation->token])
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
        $invitation = app(InvitationService::class)->invite(
            firstName: 'Twice',
            middleName: null,
            lastName: 'User',
            email: 'twice@demo1.local',
            locale: 'nl',
            roles: [],
            invitedBy: $this->actor,
            organisationId: $this->org->id,
        );
        app(InvitationService::class)->accept($invitation->token, 'First!Pwd2026');

        Livewire::test('auth.activate', ['token' => $invitation->token])
            ->set('password', 'Second!Pwd2026')
            ->set('password_confirmation', 'Second!Pwd2026')
            ->call('submit')
            ->assertHasErrors('token');
    });

    it('rejects mismatched password confirmation', function () {
        Mail::fake();
        $invitation = app(InvitationService::class)->invite(
            firstName: 'Mismatch',
            middleName: null,
            lastName: 'User',
            email: 'mismatch@demo1.local',
            locale: 'nl',
            roles: [],
            invitedBy: $this->actor,
            organisationId: $this->org->id,
        );

        Livewire::test('auth.activate', ['token' => $invitation->token])
            ->set('password', 'Aaa!Pwd2026')
            ->set('password_confirmation', 'Bbb!Pwd2026')
            ->call('submit')
            ->assertHasErrors('password');
    });

    it('rejects unsigned URL access to the activation route', function () {
        Mail::fake();
        $invitation = app(InvitationService::class)->invite(
            firstName: 'Unsigned',
            middleName: null,
            lastName: 'User',
            email: 'unsigned@demo1.local',
            locale: 'nl',
            roles: [],
            invitedBy: $this->actor,
            organisationId: $this->org->id,
        );

        $this->get('https://skv1.test/invitations/'.$invitation->token.'/accept')
            ->assertStatus(403);
    });
});

describe('InvitationMail URL generation', function () {
    it('points the activation URL to the invitee organisation subdomain', function () {
        Mail::fake();

        app(InvitationService::class)->invite(
            firstName: 'Tenant',
            middleName: null,
            lastName: 'User',
            email: 'tenant-mail@demo1.local',
            locale: 'nl',
            roles: [],
            invitedBy: $this->actor,
            organisationId: $this->org->id,
        );

        Mail::assertQueued(InvitationMail::class, function (InvitationMail $mail) {
            $url = $mail->content()->with['url'];

            return str_contains($url, 'https://demo1.skv1.test/invitations/');
        });
    });
});
