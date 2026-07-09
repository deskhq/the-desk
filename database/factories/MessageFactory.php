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

    /**
     * Indicate that the message is a reply in another message's thread.
     *
     * The reply inherits the root's channel so it stays consistent with the
     * one-channel-per-thread rule the request layer enforces.
     */
    public function inThread(Message $root): static
    {
        return $this->state(fn (array $attributes) => [
            'thread_root_id' => $root->id,
            'channel_id' => $root->channel_id,
        ]);
    }

    /**
     * Indicate that the thread reply is also surfaced in the main timeline.
     */
    public function sentToChannel(): static
    {
        return $this->state(fn (array $attributes) => [
            'sent_to_channel' => true,
        ]);
    }
}
