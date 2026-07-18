<?php

namespace Database\Factories;

use App\Enums\WebhookEvent;
use App\Enums\WebhookSubscriptionStatus;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookSubscription>
 */
class WebhookSubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'created_by' => User::factory(),
            'name' => fake()->words(2, true),
            'url' => fake()->url(),
            'secret' => WebhookSubscription::generateSecret(),
            'events' => [WebhookEvent::MessageCreated->value],
            'channel_ids' => null,
            'status' => WebhookSubscriptionStatus::Active,
            'consecutive_failures' => 0,
            'last_success_at' => null,
            'disabled_at' => null,
        ];
    }

    /**
     * Subscribe to every curated event.
     */
    public function allEvents(): static
    {
        return $this->state(fn (array $attributes): array => [
            'events' => WebhookEvent::values(),
        ]);
    }

    /**
     * Restrict the subscription to a specific channel allow-list.
     *
     * @param  list<string>  $channelIds
     */
    public function forChannels(array $channelIds): static
    {
        return $this->state(fn (array $attributes): array => [
            'channel_ids' => $channelIds,
        ]);
    }

    /**
     * Mark the subscription as auto-disabled.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => WebhookSubscriptionStatus::Disabled,
            'disabled_at' => now(),
        ]);
    }
}
