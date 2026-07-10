<?php

namespace Database\Factories;

use App\Enums\ScheduledMessageStatus;
use App\Models\Channel;
use App\Models\Message;
use App\Models\ScheduledMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduledMessage>
 */
class ScheduledMessageFactory extends Factory
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
            'client_uuid' => fake()->uuid(),
            'body' => fake()->realText(120),
            'reply_to_id' => null,
            'send_at' => now()->addHour(),
            'status' => ScheduledMessageStatus::Pending,
            'sent_at' => null,
            'cancelled_at' => null,
            'failed_at' => null,
            'failure_reason' => null,
        ];
    }

    /**
     * Indicate that the scheduled message quotes another message inline.
     */
    public function replyTo(Message $parent): static
    {
        return $this->state(fn (array $attributes) => [
            'reply_to_id' => $parent->id,
        ]);
    }

    /**
     * Indicate that the scheduled message has already been delivered.
     */
    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ScheduledMessageStatus::Sent,
            'sent_at' => now(),
        ]);
    }

    /**
     * Indicate that the scheduled message has been cancelled by its author.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ScheduledMessageStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }
}
