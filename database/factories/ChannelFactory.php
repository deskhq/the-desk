<?php

namespace Database\Factories;

use App\Enums\ChannelType;
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
            'type' => ChannelType::Standard,
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
        return $this->state(fn (array $attributes): array => [
            'visibility' => ChannelVisibility::Private,
        ]);
    }

    /**
     * Indicate that the channel is archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'archived_at' => now(),
        ]);
    }

    /**
     * Indicate that the channel is a nameless 1:1 direct message.
     *
     * The caller attaches the participants and sets the real `dm_key`; the
     * placeholder here just satisfies the unique index for standalone rows.
     */
    public function direct(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => null,
            'slug' => 'dm-'.Str::lower(Str::random(12)),
            'visibility' => ChannelVisibility::Private,
            'type' => ChannelType::Direct,
            'dm_key' => (string) Str::uuid(),
        ]);
    }
}
