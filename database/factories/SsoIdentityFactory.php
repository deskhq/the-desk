<?php

namespace Database\Factories;

use App\Models\SsoIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SsoIdentity>
 */
class SsoIdentityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => 'oidc',
            'provider_id' => fake()->unique()->uuid(),
        ];
    }

    /**
     * Indicate the directory the identity belongs to.
     */
    public function provider(string $provider): static
    {
        return $this->state(fn (array $attributes): array => [
            'provider' => $provider,
        ]);
    }
}
