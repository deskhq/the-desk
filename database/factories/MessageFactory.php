<?php

namespace Database\Factories;

use App\Enums\MessageType;
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
            'type' => MessageType::Standard,
            'edited_at' => null,
        ];
    }

    /**
     * Indicate that the message is a "member joined" system notice: an inert,
     * bodyless row whose author is the joiner it records.
     */
    public function memberJoined(): static
    {
        return $this->system(MessageType::MemberJoined);
    }

    /**
     * Indicate that the message is a "member left" system notice: an inert,
     * bodyless row whose author is the leaver it records.
     */
    public function memberLeft(): static
    {
        return $this->system(MessageType::MemberLeft);
    }

    /**
     * Indicate that the message is a system notice of the given type — a centered,
     * inert timeline line that carries no body (the client renders it from the
     * type and actor), mirroring how the real join/leave paths post one.
     */
    public function system(MessageType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
            'body' => '',
        ]);
    }

    /**
     * Indicate that the message is a poll: a bodyless, first-class row whose
     * votable question and options live in the related `polls` table.
     */
    public function poll(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => MessageType::Poll,
            'body' => '',
        ]);
    }

    /**
     * Indicate that the message has been edited.
     */
    public function edited(): static
    {
        return $this->state(fn (array $attributes): array => [
            'edited_at' => now(),
        ]);
    }

    /**
     * Indicate that the message quotes another message inline.
     */
    public function replyTo(Message $parent): static
    {
        return $this->state(fn (array $attributes): array => [
            'reply_to_id' => $parent->id,
        ]);
    }

    /**
     * Indicate that the message forwards another message into its channel.
     */
    public function forwardedFrom(Message $source): static
    {
        return $this->state(fn (array $attributes): array => [
            'forwarded_from_id' => $source->id,
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
        return $this->state(fn (array $attributes): array => [
            'thread_root_id' => $root->id,
            'channel_id' => $root->channel_id,
        ]);
    }

    /**
     * Indicate that the thread reply is also surfaced in the main timeline.
     */
    public function sentToChannel(): static
    {
        return $this->state(fn (array $attributes): array => [
            'sent_to_channel' => true,
        ]);
    }
}
