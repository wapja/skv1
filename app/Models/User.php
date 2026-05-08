<?php

namespace App\Models;

use App\Contracts\TenantOwned;
use App\Models\Concerns\BelongsToOrganisation;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Lab404\Impersonate\Models\Impersonate;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'name', 'email', 'password',
    'organisation_id', 'is_super_admin', 'status',
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
            'is_super_admin' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'activation_expires_at' => 'datetime',
            'activated_at' => 'datetime',
            'two_factor_enabled_at' => 'datetime',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }
}
