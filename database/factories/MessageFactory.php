<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
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
            'edited_at' => null,
        ];
    }

    /**
     * Indicate that the message has been edited.
     */
    public function edited(): static
    {
        return $this->state(fn (array $attributes) => [
            'edited_at' => now(),
        ]);
    }

    /**
     * Indicate that the message quotes another message inline.
     */
    public function replyTo(Message $parent): static
    {
        return $this->state(fn (array $attributes) => [
            'reply_to_id' => $parent->id,
        ]);
    }
}
