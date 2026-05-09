<?php

namespace App\Models;

use App\Contracts\TenantOwned;
use App\Models\Concerns\BelongsToOrganisation;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Lab404\Impersonate\Models\Impersonate;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'first_name', 'middle_name', 'last_name',
    'internal_id', 'phone', 'address',
    'start_date', 'end_date',
    'email', 'password',
    'organisation_id', 'status',
    'activation_token', 'activation_expires_at', 'activated_at',
    'two_factor_secret', 'two_factor_enabled_at',
    'locale',
])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'activation_token'])]
class User extends Authenticatable implements TenantOwned
{
    /** @use HasFactory<UserFactory> */
    use BelongsToOrganisation, HasFactory, HasRoles, Impersonate, Notifiable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'activation_expires_at' => 'datetime',
            'activated_at' => 'datetime',
            'two_factor_enabled_at' => 'datetime',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    protected function name(): Attribute
    {
        return Attribute::get(fn () => trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
        ]))));
    }

    protected function middleName(): Attribute
    {
        return Attribute::set(fn ($value) => $value === '' ? null : $value);
    }

    protected function internalId(): Attribute
    {
        return Attribute::set(fn ($value) => $value === '' ? null : $value);
    }

    protected function phone(): Attribute
    {
        return Attribute::set(fn ($value) => $value === '' ? null : $value);
    }

    protected function address(): Attribute
    {
        return Attribute::set(fn ($value) => $value === '' ? null : $value);
    }

    protected function endDate(): Attribute
    {
        return Attribute::set(fn ($value) => $value === '' ? null : $value);
    }

    public function isSuperAdmin(): bool
    {
        // Spatie's roles() relationship is team-scoped, which means it only
        // sees role pivots for the currently-set team_id. Super-admins hold
        // the role in every organisation (or in the apex template), so we
        // briefly disable team-scoping to ask "does this user hold super_admin
        // ANYWHERE?" — mirroring Spatie's own bootHasRoles() pattern.
        $registrar = app(PermissionRegistrar::class);
        $teamsEnabled = $registrar->teams;
        $registrar->teams = false;

        try {
            return $this->roles()->where('name', 'super_admin')->exists();
        } finally {
            $registrar->teams = $teamsEnabled;
        }
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }
}
