<?php

use App\Exceptions\Impersonation\CannotImpersonateSuperAdmin;
use App\Exceptions\Impersonation\ImpersonationDepthExceeded;
use App\Exceptions\Impersonation\ImpersonationNotPermitted;
use App\Models\Organisation;
use App\Models\User;
use App\Services\ImpersonationGuard;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->orgA = Organisation::factory()->create(['slug' => 'a']);
    $this->orgB = Organisation::factory()->create(['slug' => 'b']);

    app()->instance('currentOrganisation', $this->orgA);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->orgA->id);

    $this->orgAdmin = User::factory()->for($this->orgA)->create(['email' => 'admin@a.local']);
    $this->orgAdmin->assignRole('organisation_admin');

    $this->regularUser = User::factory()->for($this->orgA)->create(['email' => 'user@a.local']);
    $this->otherOrgUser = User::factory()->for($this->orgB)->create(['email' => 'user@b.local']);
    $this->superAdmin = User::factory()->superAdmin()->create(['organisation_id' => null, 'email' => 'super@x.local']);
});

it('lets a super_admin impersonate any non-super-admin user across orgs', function () {
    $this->actingAs($this->superAdmin);

    app(ImpersonationGuard::class)->start($this->superAdmin, $this->otherOrgUser, 'Diagnose login bug');

    expect(auth()->user()->isImpersonated())->toBeTrue()
        ->and(auth()->user()->id)->toBe($this->otherOrgUser->id);
});

it('lets an org_admin impersonate a non-admin user within the same organisation', function () {
    $this->actingAs($this->orgAdmin);

    app(ImpersonationGuard::class)->start($this->orgAdmin, $this->regularUser, 'Reproduceer fout');

    expect(auth()->user()->isImpersonated())->toBeTrue()
        ->and(auth()->user()->id)->toBe($this->regularUser->id);
});

it('forbids org_admin from impersonating a user in another organisation', function () {
    $this->actingAs($this->orgAdmin);

    expect(fn () => app(ImpersonationGuard::class)->start($this->orgAdmin, $this->otherOrgUser, 'Some reason'))
        ->toThrow(ImpersonationNotPermitted::class);
});

it('forbids org_admin from impersonating another org_admin', function () {
    $secondAdmin = User::factory()->for($this->orgA)->create(['email' => 'admin2@a.local']);
    $secondAdmin->assignRole('organisation_admin');

    $this->actingAs($this->orgAdmin);

    expect(fn () => app(ImpersonationGuard::class)->start($this->orgAdmin, $secondAdmin, 'Reason'))
        ->toThrow(ImpersonationNotPermitted::class);
});

it('forbids anybody (including super_admin) from impersonating a super_admin', function () {
    $this->actingAs($this->superAdmin);

    $secondSuper = User::factory()->superAdmin()->create(['organisation_id' => null]);

    expect(fn () => app(ImpersonationGuard::class)->start($this->superAdmin, $secondSuper, 'Reason'))
        ->toThrow(CannotImpersonateSuperAdmin::class);
});

it('forbids nested impersonation (depth=1)', function () {
    $this->actingAs($this->superAdmin);

    app(ImpersonationGuard::class)->start($this->superAdmin, $this->regularUser, 'Reason');
    $deeperTarget = User::factory()->for($this->orgA)->create();

    expect(fn () => app(ImpersonationGuard::class)->start($this->superAdmin, $deeperTarget, 'Reason'))
        ->toThrow(ImpersonationDepthExceeded::class);
});

it('rejects empty reason', function () {
    $this->actingAs($this->superAdmin);

    expect(fn () => app(ImpersonationGuard::class)->start($this->superAdmin, $this->regularUser, ''))
        ->toThrow(ImpersonationNotPermitted::class);
});

it('rejects reason longer than 500 chars', function () {
    $this->actingAs($this->superAdmin);

    expect(fn () => app(ImpersonationGuard::class)->start($this->superAdmin, $this->regularUser, str_repeat('x', 501)))
        ->toThrow(ImpersonationNotPermitted::class);
});

it('logs an activity entry with reason and impersonated_as, causer is the real actor', function () {
    $this->actingAs($this->superAdmin);

    app(ImpersonationGuard::class)->start($this->superAdmin, $this->regularUser, 'Bug repro');

    $activity = Activity::where('log_name', 'impersonation')->latest('id')->first();
    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($this->superAdmin->id)
        ->and($activity->subject_id)->toBe($this->regularUser->id)
        ->and($activity->properties['reason'])->toBe('Bug repro')
        ->and($activity->properties['impersonated_as'])->toBe('user@a.local');
});

it('stops the impersonation and clears the session', function () {
    $this->actingAs($this->superAdmin);

    app(ImpersonationGuard::class)->start($this->superAdmin, $this->regularUser, 'Reason');
    expect(auth()->user()->isImpersonated())->toBeTrue();

    app(ImpersonationGuard::class)->stop();

    expect(session()->has('impersonated_by'))->toBeFalse();
});
