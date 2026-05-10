<?php

use App\Http\Controllers\HealthCheckController;
use App\Http\Middleware\HealthCheckAuth;
use App\Services\ImpersonationGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('welcome');

Route::middleware('guest')->group(function () {
    Route::livewire('/login', 'auth.login')->name('login');
    Route::livewire('/forgot-password', 'auth.forgot-password')->name('password.request');
    Route::livewire('/reset-password/{token}', 'auth.reset-password')->name('password.reset');
});

Route::livewire('/invitations/{token}/accept', 'auth.activate')
    ->middleware(['signed', 'guest'])
    ->name('invitation.accept');

Route::get('/health-check', HealthCheckController::class)
    ->middleware(HealthCheckAuth::class)
    ->name('health-check');

Route::middleware('auth')->group(function () {
    Route::view('/dashboard', 'dashboard')->name('dashboard');

    Route::livewire('/admin/users', 'users.index')->name('users.index');
    Route::livewire('/admin/users/{user}/edit', 'users.edit')->name('users.edit');
    Route::livewire('/admin/invitations', 'invitations.index')->name('invitations.index');
    Route::livewire('/admin/roles', 'roles.index')->name('roles.index');
    Route::livewire('/admin/roles/create', 'roles.edit')->name('roles.create');
    Route::livewire('/admin/roles/{role}/edit', 'roles.edit')->name('roles.edit');

    Route::livewire('/admin/organisations', 'organisations.index')->name('organisations.index');
    Route::livewire('/admin/organisations/create', 'organisations.edit')->name('organisations.create');
    Route::livewire('/admin/organisations/{organisation}/edit', 'organisations.edit')->name('organisations.edit');

    Route::livewire('/admin/activity', 'activity.index')->name('activity.index');

    Route::post('/impersonate/stop', function (ImpersonationGuard $guard) {
        $guard->stop();

        return redirect()->route('dashboard');
    })->name('impersonate.stop');

    Route::post('/logout', function () {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');
});
