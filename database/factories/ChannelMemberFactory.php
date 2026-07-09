<?php

namespace Database\Factories;

use App\Enums\NotificationLevel;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelMember>
 */
class ChannelMemberFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'user_id' => User::factory(),
            'last_read_message_id' => null,
            'muted' => false,
            'notification_level' => NotificationLevel::All,
            'draft' => null,
        ];
    }

    /**
     * Indicate that the membership is muted.
     */
    public function muted(): static
    {
        return $this->state(fn (array $attributes): array => ['muted' => true]);
    }

    /**
     * Indicate the membership has a pending composer draft.
     */
    public function draft(string $draft): static
    {
        return $this->state(fn (array $attributes): array => ['draft' => $draft]);
    }

    /**
     * Indicate the membership's notification level.
     */
    public function notificationLevel(NotificationLevel $level): static
    {
        return $this->state(fn (array $attributes): array => ['notification_level' => $level]);
    }
}
