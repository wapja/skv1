<?php

use App\Http\Controllers\HealthCheckController;
use App\Http\Middleware\HealthCheckAuth;
use App\Livewire\Activity\Index as ActivityIndex;
use App\Livewire\Auth\Activate;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\Organisations\Edit as OrganisationEdit;
use App\Livewire\Organisations\Index as OrganisationIndex;
use App\Livewire\Roles\Edit as RoleEdit;
use App\Livewire\Roles\Index as RoleIndex;
use App\Livewire\Users\Edit as UserEdit;
use App\Livewire\Users\Index as UserIndex;
use App\Services\ImpersonationGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('welcome');

Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');
    Route::get('/forgot-password', ForgotPassword::class)->name('password.request');
    Route::get('/reset-password/{token}', ResetPassword::class)->name('password.reset');
});

Route::get('/invitations/{token}/accept', Activate::class)
    ->middleware(['signed', 'guest'])
    ->name('invitation.accept');

Route::get('/health-check', HealthCheckController::class)
    ->middleware(HealthCheckAuth::class)
    ->name('health-check');

Route::middleware('auth')->group(function () {
    Route::view('/dashboard', 'dashboard')->name('dashboard');

    Route::get('/admin/users', UserIndex::class)->name('users.index');
    Route::get('/admin/users/{user}/edit', UserEdit::class)->name('users.edit');
    Route::get('/admin/roles', RoleIndex::class)->name('roles.index');
    Route::get('/admin/roles/{role}/edit', RoleEdit::class)->name('roles.edit');

    Route::get('/admin/organisations', OrganisationIndex::class)->name('organisations.index');
    Route::get('/admin/organisations/create', OrganisationEdit::class)->name('organisations.create');
    Route::get('/admin/organisations/{organisation}/edit', OrganisationEdit::class)->name('organisations.edit');

    Route::get('/admin/activity', ActivityIndex::class)->name('activity.index');

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
