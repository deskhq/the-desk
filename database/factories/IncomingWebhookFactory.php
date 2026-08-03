<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\IncomingWebhook;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IncomingWebhook>
 */
class IncomingWebhookFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Materialize one team and reuse it for the channel and bot, so a bare
        // ->create() yields a coherent (team, channel, bot) whose ids all agree.
        $team = Team::factory()->create();

        return [
            'team_id' => $team->id,
            'channel_id' => Channel::factory()->for($team),
            'bot_id' => User::factory()->bot($team),
            'created_by' => User::factory(),
            'name' => fake()->words(2, true),
            'token_hash' => IncomingWebhook::hashToken(Str::random(48)),
            'signing_secret' => null,
            'revoked_at' => null,
        ];
    }

    /**
     * Indicate that the webhook has been revoked and no longer resolves.
     */
    public function revoked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'revoked_at' => now(),
        ]);
    }

    /**
     * Indicate that the webhook requires HMAC-signed requests with the given
     * shared secret.
     */
    public function signedWith(string $secret): static
    {
        return $this->state(fn (array $attributes): array => [
            'signing_secret' => $secret,
        ]);
    }
}
