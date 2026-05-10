<?php

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    config(['app.apex_domain' => 'skv1.test']);

    $this->org = Organisation::factory()->create(['slug' => 'demo1']);

    $this->user = User::factory()->for($this->org)->create([
        'email' => 'admin@demo1.local',
        'password' => Hash::make('Password123!'),
        'status' => 'active',
    ]);
});

it('renders the login page on a tenant subdomain', function () {
    $this->get('https://demo1.skv1.test/login')
        ->assertOk()
        ->assertSeeLivewire('auth.login');
});

it('signs the user in with valid credentials and redirects to dashboard', function () {
    app()->instance('currentOrganisation', $this->org);

    Livewire::test('auth.login')
        ->set('email', 'admin@demo1.local')
        ->set('password', 'Password123!')
        ->call('submit')
        ->assertRedirect(route('dashboard'));

    expect(auth()->check())->toBeTrue()
        ->and(auth()->user()->email)->toBe('admin@demo1.local');
});

it('rejects invalid credentials with an error', function () {
    app()->instance('currentOrganisation', $this->org);

    Livewire::test('auth.login')
        ->set('email', 'admin@demo1.local')
        ->set('password', 'WrongPassword')
        ->call('submit')
        ->assertHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('rejects users from a different tenant', function () {
    $other = Organisation::factory()->create(['slug' => 'demo2']);
    User::factory()->for($other)->create([
        'email' => 'admin@demo2.local',
        'password' => Hash::make('Password123!'),
        'status' => 'active',
    ]);

    app()->instance('currentOrganisation', $this->org);

    Livewire::test('auth.login')
        ->set('email', 'admin@demo2.local')
        ->set('password', 'Password123!')
        ->call('submit')
        ->assertHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('rejects disabled users even with correct credentials', function () {
    $this->user->update(['status' => 'disabled']);
    app()->instance('currentOrganisation', $this->org);

    Livewire::test('auth.login')
        ->set('email', 'admin@demo1.local')
        ->set('password', 'Password123!')
        ->call('submit')
        ->assertHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('logs the user out via the logout route', function () {
    $this->actingAs($this->user);

    $this->post('https://demo1.skv1.test/logout')
        ->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});
