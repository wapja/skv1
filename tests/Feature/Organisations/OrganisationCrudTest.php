<?php

use App\Livewire\Organisations\Edit;
use App\Livewire\Organisations\Index;
use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\DemoOrganisationsSeeder;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->orgA = Organisation::factory()->create(['slug' => 'a']);
    $this->orgB = Organisation::factory()->create(['slug' => 'b']);

    app()->instance('currentOrganisation', $this->orgA);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->orgA->id);

    $this->orgAdmin = User::factory()->for($this->orgA)->create();
    $this->orgAdmin->assignRole('organisation_admin');

    $this->superAdmin = User::factory()->superAdmin()->create(['organisation_id' => null]);
});

describe('OrganisationPolicy', function () {
    it('forbids org_admin from listing organisations', function () {
        $this->actingAs($this->orgAdmin);
        expect(Gate::allows('viewAny', Organisation::class))->toBeFalse();
    });

    it('forbids org_admin from creating, updating or deleting organisations', function () {
        $this->actingAs($this->orgAdmin);
        expect(Gate::allows('create', Organisation::class))->toBeFalse()
            ->and(Gate::allows('update', $this->orgA))->toBeFalse()
            ->and(Gate::allows('delete', $this->orgA))->toBeFalse();
    });

    it('allows super_admin to do everything', function () {
        $this->actingAs($this->superAdmin);
        expect(Gate::allows('viewAny', Organisation::class))->toBeTrue()
            ->and(Gate::allows('create', Organisation::class))->toBeTrue()
            ->and(Gate::allows('update', $this->orgA))->toBeTrue()
            ->and(Gate::allows('delete', $this->orgA))->toBeTrue();
    });
});

describe('Organisations Index Livewire', function () {
    it('lists all organisations for super_admin', function () {
        $this->actingAs($this->superAdmin);

        Livewire::test(Index::class)
            ->assertSee('a')
            ->assertSee('b');
    });

    it('returns 403 for non-super-admin actors', function () {
        $this->actingAs($this->orgAdmin);

        Livewire::test(Index::class)
            ->assertStatus(403);
    });
});

describe('Organisations Edit Livewire', function () {
    it('updates an organisation for super_admin', function () {
        $this->actingAs($this->superAdmin);

        Livewire::test(Edit::class, ['organisation' => $this->orgA])
            ->set('name', 'Renamed A')
            ->set('slug', 'renamed-a')
            ->set('description', 'Hello')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('organisations.index'));

        $this->orgA->refresh();
        expect($this->orgA->name)->toBe('Renamed A')
            ->and($this->orgA->slug)->toBe('renamed-a')
            ->and($this->orgA->description)->toBe('Hello');
    });

    it('rejects duplicate slugs', function () {
        $this->actingAs($this->superAdmin);

        Livewire::test(Edit::class, ['organisation' => $this->orgA])
            ->set('slug', 'b')
            ->call('save')
            ->assertHasErrors('slug');
    });

    it('forbids non-super-admin from editing', function () {
        $this->actingAs($this->orgAdmin);

        Livewire::test(Edit::class, ['organisation' => $this->orgA])
            ->assertStatus(403);
    });

    it('soft-deletes via the delete action and cascades to users', function () {
        $u1 = User::factory()->for($this->orgA)->create();
        $u2 = User::factory()->for($this->orgA)->create();

        $this->actingAs($this->superAdmin);

        Livewire::test(Index::class)
            ->call('delete', $this->orgA->id)
            ->assertHasNoErrors();

        expect(Organisation::find($this->orgA->id))->toBeNull()
            ->and(User::withoutTenantScope()->where('organisation_id', $this->orgA->id)->count())->toBe(0);
    });
});

describe('Demo seeders', function () {
    it('seeds 2 organisations with admin + user accounts plus a global super admin', function () {
        $this->seed(DemoOrganisationsSeeder::class);
        $this->seed(DemoUsersSeeder::class);

        $demo1 = Organisation::where('slug', 'demo1')->firstOrFail();
        $demo2 = Organisation::where('slug', 'demo2')->firstOrFail();

        expect(User::withoutTenantScope()->where('email', 'admin@demo1.local')->exists())->toBeTrue()
            ->and(User::withoutTenantScope()->where('email', 'user@demo1.local')->exists())->toBeTrue()
            ->and(User::withoutTenantScope()->where('email', 'admin@demo2.local')->exists())->toBeTrue()
            ->and(User::withoutTenantScope()->where('email', 'user@demo2.local')->exists())->toBeTrue()
            ->and(User::withoutTenantScope()->where('email', 'super@example.local')->where('is_super_admin', true)->exists())->toBeTrue();

        $admin1 = User::withoutTenantScope()->where('email', 'admin@demo1.local')->first();
        expect($admin1->organisation_id)->toBe($demo1->id);

        $admin2 = User::withoutTenantScope()->where('email', 'admin@demo2.local')->first();
        expect($admin2->organisation_id)->toBe($demo2->id);

        $super = User::withoutTenantScope()->where('email', 'super@example.local')->first();
        expect($super->organisation_id)->toBeNull()
            ->and($super->is_super_admin)->toBeTrue();
    });
});
