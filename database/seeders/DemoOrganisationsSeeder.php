<?php

namespace Database\Seeders;

use App\Models\Organisation;
use Illuminate\Database\Seeder;

class DemoOrganisationsSeeder extends Seeder
{
    public function run(): void
    {
        Organisation::factory()->create([
            'name' => 'Demo 1',
            'slug' => 'demo1',
            'description' => 'Eerste demo-tenant.',
        ]);

        Organisation::factory()->create([
            'name' => 'Demo 2',
            'slug' => 'demo2',
            'description' => 'Tweede demo-tenant.',
        ]);
    }
}
