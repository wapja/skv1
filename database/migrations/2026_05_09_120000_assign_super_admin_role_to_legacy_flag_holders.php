<?php

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        try {
            $userIds = DB::table('users')
                ->where('is_super_admin', true)
                ->pluck('id');

            foreach ($userIds as $userId) {
                $user = User::find($userId);
                if (! $user) {
                    continue;
                }

                foreach (Organisation::all() as $org) {
                    $registrar->setPermissionsTeamId($org->id);
                    if (! $user->hasRole('super_admin')) {
                        $user->assignRole('super_admin');
                    }
                }
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }
    }

    public function down(): void
    {
        // Intentionally a no-op. Reverting the assignment is risky because
        // we cannot tell which super_admin grants pre-dated this migration
        // versus which were added later.
    }
};
