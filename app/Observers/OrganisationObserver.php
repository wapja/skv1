<?php

namespace App\Observers;

use App\Contracts\TenantOwned;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrganisationObserver
{
    /**
     * Models known to implement TenantOwned. Add new TenantOwned models here
     * so the cascade soft-delete reaches them. The observer guards each one
     * with a class_implements() check, so an accidental wrong entry is ignored.
     */
    private const TENANT_OWNED_MODELS = [
        User::class,
    ];

    public function deleted(Organisation $organisation): void
    {
        if ($organisation->isForceDeleting()) {
            return;
        }

        $timestamp = $organisation->deleted_at;

        DB::transaction(function () use ($organisation, $timestamp) {
            foreach (self::TENANT_OWNED_MODELS as $modelClass) {
                if (! in_array(TenantOwned::class, class_implements($modelClass), true)) {
                    continue;
                }

                $modelClass::query()
                    ->withoutGlobalScopes()
                    ->where('organisation_id', $organisation->id)
                    ->whereNull('deleted_at')
                    ->update([
                        'deleted_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
            }
        });
    }

    public function restoring(Organisation $organisation): void
    {
        $timestamp = $organisation->deleted_at;

        DB::transaction(function () use ($organisation, $timestamp) {
            foreach (self::TENANT_OWNED_MODELS as $modelClass) {
                if (! in_array(TenantOwned::class, class_implements($modelClass), true)) {
                    continue;
                }

                $modelClass::query()
                    ->withoutGlobalScopes()
                    ->where('organisation_id', $organisation->id)
                    ->where('deleted_at', $timestamp)
                    ->update([
                        'deleted_at' => null,
                    ]);
            }
        });
    }
}
