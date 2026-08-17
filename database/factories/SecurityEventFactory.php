<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SecurityEventType;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecurityEvent>
 */
class SecurityEventFactory extends Factory
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
            'type' => fake()->randomElement(SecurityEventType::cases()),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'is_new_device' => false,
        ];
    }

    /**
     * Record the event as a specific type.
     */
    public function ofType(SecurityEventType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
        ]);
    }

    /**
     * Flag the event as coming from an unfamiliar device.
     */
    public function newDevice(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => SecurityEventType::LoggedIn,
            'is_new_device' => true,
        ]);
    }
}
