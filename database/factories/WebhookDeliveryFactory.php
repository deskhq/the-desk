<?php

namespace Database\Factories;

use App\Enums\WebhookEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'webhook_subscription_id' => WebhookSubscription::factory(),
            'event_type' => WebhookEvent::MessageCreated->value,
            'event_id' => (string) Str::uuid(),
            'succeeded' => true,
            'response_status' => 200,
            'duration_ms' => fake()->numberBetween(20, 800),
            'attempt' => 1,
            'error' => null,
        ];
    }

    /**
     * A failed delivery attempt.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'succeeded' => false,
            'response_status' => 500,
            'error' => 'HTTP 500',
        ]);
    }
}
