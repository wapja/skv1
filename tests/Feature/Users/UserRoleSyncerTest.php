<?php

use App\Models\Organisation;
use App\Models\User;
use App\Services\UserRoleSyncer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->orgA = Organisation::factory()->create(['slug' => 'syncer-a']);
    $this->orgB = Organisation::factory()->create(['slug' => 'syncer-b']);
});

describe('UserRoleSyncer::sync', function () {
    it('assigns regular roles in primary org only', function () {
        $user = User::factory()->for($this->orgA)->create();

        app(UserRoleSyncer::class)->sync($user, ['organisation_admin'], $this->orgA->id);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->orgA->id);
        expect($user->fresh()->hasRole('organisation_admin'))->toBeTrue();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->orgB->id);
        expect($user->fresh()->hasRole('organisation_admin'))->toBeFalse();
    });

    it('propagates super_admin to all orgs when added', function () {
        $user = User::factory()->create(['organisation_id' => null]);

        app(UserRoleSyncer::class)->sync($user, ['super_admin'], $this->orgA->id);

        foreach ([$this->orgA, $this->orgB] as $org) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
            expect($user->fresh()->hasRole('super_admin'))
                ->toBeTrue("expected super_admin in {$org->slug}");
        }
    });

    it('removes super_admin from all orgs when removed', function () {
        $user = User::factory()->create(['organisation_id' => null]);

        // Pre-state: super_admin in both orgs.
        foreach ([$this->orgA, $this->orgB] as $org) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
            $user->assignRole('super_admin');
        }

        app(UserRoleSyncer::class)->sync($user, [], $this->orgA->id);

        foreach ([$this->orgA, $this->orgB] as $org) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
            expect($user->fresh()->hasRole('super_admin'))
                ->toBeFalse("expected NO super_admin in {$org->slug}");
        }
    });

    it('is idempotent when role state already matches selection', function () {
        $user = User::factory()->for($this->orgA)->create();

        app(UserRoleSyncer::class)->sync($user, ['test1'], $this->orgA->id);
        app(UserRoleSyncer::class)->sync($user, ['test1'], $this->orgA->id);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->orgA->id);
        expect($user->fresh()->getRoleNames()->all())->toBe(['test1']);
    });

    it('restores setPermissionsTeamId after the sync completes', function () {
        $user = User::factory()->for($this->orgA)->create();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->orgB->id);
        app(UserRoleSyncer::class)->sync($user, ['test2'], $this->orgA->id);

        expect(app(PermissionRegistrar::class)->getPermissionsTeamId())
            ->toBe($this->orgB->id);
    });
});
