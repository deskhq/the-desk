<?php

namespace Database\Factories;

use App\Models\CustomEmoji;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomEmoji>
 */
class CustomEmojiFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->slug(2);

        return [
            'team_id' => Team::factory(),
            'created_by' => User::factory(),
            'name' => $name,
            'path' => 'custom-emoji/'.fake()->uuid().'.png',
        ];
    }

    /**
     * Indicate the emoji uses a specific shortcode name.
     */
    public function name(string $name): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => $name,
        ]);
    }
}
