<?php

namespace App\Models\Concerns;

use App\Models\Organisation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToOrganisation
{
    public static function bootBelongsToOrganisation(): void
    {
        static::creating(function ($model) {
            if (! $model->organisation_id && $tenant = static::resolveCurrentTenant()) {
                $model->organisation_id = $tenant->id;
            }
        });

        static::addGlobalScope('organisation', function (Builder $query) {
            $user = auth()->user();
            if ($user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
                return;
            }

            if ($tenant = static::resolveCurrentTenant()) {
                $table = $query->getModel()->getTable();
                $query->where("{$table}.organisation_id", $tenant->id);
            }
        });
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function scopeWithoutTenantScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope('organisation');
    }

    protected static function resolveCurrentTenant(): ?Organisation
    {
        return app()->bound('currentOrganisation') ? app('currentOrganisation') : null;
    }
}
