<?php

namespace Database\Seeders;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

class DemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);

        foreach (['demo1', 'demo2'] as $slug) {
            $org = Organisation::where('slug', $slug)->firstOrFail();
            app()->instance('currentOrganisation', $org);
            $registrar->setPermissionsTeamId($org->id);

            $admin = User::factory()->for($org)->create([
                'name' => 'Admin '.ucfirst($slug),
                'email' => "admin@{$slug}.local",
                'password' => Hash::make('Password123!'),
                'status' => 'active',
                'activated_at' => now(),
            ]);
            $admin->assignRole('organisation_admin');

            User::factory()->for($org)->create([
                'name' => 'User '.ucfirst($slug),
                'email' => "user@{$slug}.local",
                'password' => Hash::make('Password123!'),
                'status' => 'active',
                'activated_at' => now(),
            ]);
        }

        app()->forgetInstance('currentOrganisation');

        User::factory()->superAdmin()->create([
            'name' => 'Super Admin',
            'email' => 'super@example.local',
            'organisation_id' => null,
            'password' => Hash::make('Password123!'),
            'status' => 'active',
            'activated_at' => now(),
        ]);
    }
}
