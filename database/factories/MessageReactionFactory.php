<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageReaction>
 */
class MessageReactionFactory extends Factory
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
            'user_id' => User::factory(),
            'emoji' => fake()->randomElement(['👍', '❤️', '😂', '🎉', '👀', '🙌']),
        ];
    }

    /**
     * Indicate the reaction uses a specific emoji.
     */
    public function emoji(string $emoji): static
    {
        return $this->state(fn (array $attributes) => [
            'emoji' => $emoji,
        ]);
    }
}
