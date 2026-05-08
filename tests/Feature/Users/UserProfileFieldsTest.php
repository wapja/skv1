<?php

use App\Models\Organisation;
use App\Models\User;
use App\Livewire\Users\Edit as UserEdit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->org = Organisation::factory()->create();
    app()->instance('currentOrganisation', $this->org);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
});

it('factory produces a complete profile with required fields populated', function () {
    $user = User::factory()->for($this->org)->create();

    expect($user->first_name)->not->toBeEmpty()
        ->and($user->last_name)->not->toBeEmpty()
        ->and($user->start_date)->not->toBeNull();
});

it('name accessor composes first, middle, and last with no extra whitespace', function () {
    $with = User::factory()->for($this->org)->create([
        'first_name' => 'Frank',
        'middle_name' => 'van der',
        'last_name' => 'Meer',
    ]);
    $without = User::factory()->for($this->org)->create([
        'first_name' => 'Jane',
        'middle_name' => null,
        'last_name' => 'Doe',
    ]);

    expect($with->name)->toBe('Frank van der Meer')
        ->and($without->name)->toBe('Jane Doe');
});

it('rejects an end_date that precedes start_date via the edit form', function () {
    $admin = User::factory()->for($this->org)->create();
    $admin->assignRole('organisation_admin');

    $target = User::factory()->for($this->org)->create([
        'start_date' => '2026-05-01',
    ]);

    Livewire::actingAs($admin)
        ->test(UserEdit::class, ['user' => $target])
        ->set('start_date', '2026-05-01')
        ->set('end_date', '2026-04-30')
        ->call('save')
        ->assertHasErrors(['end_date']);
});

it('persists the new optional fields when set, and stores empty strings as null', function () {
    $admin = User::factory()->for($this->org)->create();
    $admin->assignRole('organisation_admin');

    $target = User::factory()->for($this->org)->create();

    Livewire::actingAs($admin)
        ->test(UserEdit::class, ['user' => $target])
        ->set('internal_id', 'EMP-001')
        ->set('phone', '+31 6 12345678')
        ->set('address', 'Hoofdstraat 1, Amsterdam')
        ->call('save');

    $target->refresh();

    expect($target->internal_id)->toBe('EMP-001')
        ->and($target->phone)->toBe('+31 6 12345678')
        ->and($target->address)->toBe('Hoofdstraat 1, Amsterdam')
        ->and($target->middle_name)->toBeNull()
        ->and($target->end_date)->toBeNull();
});

it('coerces empty strings on optional fields back to null when cleared via the form', function () {
    $admin = User::factory()->for($this->org)->create();
    $admin->assignRole('organisation_admin');

    $target = User::factory()->for($this->org)->create([
        'middle_name' => 'van der',
        'internal_id' => 'EMP-OLD',
        'phone' => '0123456',
        'address' => 'Oude straat 1',
    ]);

    Livewire::actingAs($admin)
        ->test(UserEdit::class, ['user' => $target])
        ->set('middle_name', '')
        ->set('internal_id', '')
        ->set('phone', '')
        ->set('address', '')
        ->call('save');

    $target->refresh();

    expect($target->middle_name)->toBeNull()
        ->and($target->internal_id)->toBeNull()
        ->and($target->phone)->toBeNull()
        ->and($target->address)->toBeNull();
});
