<?php

namespace Database\Factories;

use App\Models\ChannelSection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelSection>
 */
class ChannelSectionFactory extends Factory
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
            'team_id' => Team::factory(),
            'name' => fake()->unique()->words(2, true),
            'position' => 0,
            'collapsed' => false,
        ];
    }

    /**
     * Indicate the section is collapsed.
     */
    public function collapsed(): static
    {
        return $this->state(fn (array $attributes): array => ['collapsed' => true]);
    }

    /**
     * Place the section at the given position among the user's sections.
     */
    public function position(int $position): static
    {
        return $this->state(fn (array $attributes): array => ['position' => $position]);
    }
}
