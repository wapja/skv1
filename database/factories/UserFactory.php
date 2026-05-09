<?php

namespace Database\Factories;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->boolean(20)
                ? fake()->randomElement(['van', 'de', 'van der', 'den', 'ten', 'ter'])
                : null,
            'last_name' => fake()->lastName(),
            'start_date' => today(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'status' => 'active',
            'activated_at' => now(),
            'locale' => 'nl',
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function pendingActivation(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending_activation',
            'activated_at' => null,
            'password' => null,
            'activation_token' => Str::random(64),
            'activation_expires_at' => now()->addDays(7),
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'disabled',
        ]);
    }

    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'organisation_id' => null,
        ])->afterCreating(function (User $user) {
            $registrar = app(PermissionRegistrar::class);
            $previousTeamId = $registrar->getPermissionsTeamId();

            try {
                foreach (Organisation::all() as $org) {
                    $registrar->setPermissionsTeamId($org->id);

                    if (! $user->hasRole('super_admin')) {
                        $user->assignRole('super_admin');
                    }
                }
            } finally {
                $registrar->setPermissionsTeamId($previousTeamId);
            }
        });
    }
}
