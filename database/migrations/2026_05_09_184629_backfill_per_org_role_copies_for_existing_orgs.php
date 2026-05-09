<?php

use App\Services\RoleBackfiller;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(RoleBackfiller::class)->backfillExistingOrganisations();
    }

    public function down(): void
    {
        // No-op: data migration; reverting would discard user-permission state.
    }
};
