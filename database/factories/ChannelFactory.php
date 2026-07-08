<?php

namespace Database\Factories;

use App\Enums\ChannelVisibility;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Channel>
 */
class ChannelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'team_id' => Team::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'visibility' => ChannelVisibility::Public,
            'topic' => null,
            'created_by' => User::factory(),
            'archived_at' => null,
        ];
    }

    /**
     * Indicate that the channel is private.
     */
    public function private(): static
    {
        return $this->state(fn (array $attributes) => [
            'visibility' => ChannelVisibility::Private,
        ]);
    }

    /**
     * Indicate that the channel is archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'archived_at' => now(),
        ]);
    }
}
