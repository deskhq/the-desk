<?php

namespace Database\Factories;

use App\Enums\AttachmentStatus;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    /**
     * Define the model's default state: a pending image upload owned by a user
     * and channel, not yet claimed by any message.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => null,
            'user_id' => User::factory(),
            'channel_id' => Channel::factory(),
            'disk' => config('attachments.disk'),
            'path' => 'attachments/'.fake()->uuid().'/'.fake()->uuid().'.png',
            'original_filename' => fake()->word().'.png',
            'mime_type' => 'image/png',
            'size_bytes' => fake()->numberBetween(1_000, 5_000_000),
            'width' => 800,
            'height' => 600,
            'status' => AttachmentStatus::Pending,
        ];
    }

    /**
     * Indicate the attachment is a non-image file (no dimensions).
     */
    public function document(): static
    {
        return $this->state(fn (array $attributes): array => [
            'original_filename' => fake()->word().'.pdf',
            'mime_type' => 'application/pdf',
            'width' => null,
            'height' => null,
        ]);
    }

    /**
     * Indicate the attachment is an SVG — image-shaped but download-only.
     */
    public function svg(): static
    {
        return $this->state(fn (array $attributes): array => [
            'original_filename' => fake()->word().'.svg',
            'mime_type' => 'image/svg+xml',
        ]);
    }

    /**
     * Indicate the attachment has been claimed by (attached to) a message.
     */
    public function attachedTo(Message $message): static
    {
        return $this->state(fn (array $attributes): array => [
            'message_id' => $message->id,
            'channel_id' => $message->channel_id,
            'status' => AttachmentStatus::Attached,
        ]);
    }
}
