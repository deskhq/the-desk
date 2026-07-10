<?php

namespace Database\Factories;

use App\Enums\LinkPreviewStatus;
use App\Models\Message;
use App\Models\MessageLinkPreview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageLinkPreview>
 */
class MessageLinkPreviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'url' => fake()->url(),
            'status' => LinkPreviewStatus::Pending,
            'title' => null,
            'description' => null,
            'image_url' => null,
            'site_name' => null,
            'position' => 0,
        ];
    }

    /**
     * Indicate that the preview has been successfully unfurled.
     */
    public function ready(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LinkPreviewStatus::Ready,
            'title' => fake()->sentence(4),
            'description' => fake()->sentence(10),
            'image_url' => fake()->imageUrl(),
            'site_name' => fake()->domainName(),
        ]);
    }

    /**
     * Indicate that the preview failed to unfurl.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LinkPreviewStatus::Failed,
        ]);
    }
}
