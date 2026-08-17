<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Message;
use App\Models\ThreadRead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThreadRead>
 */
class ThreadReadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'thread_root_id' => Message::factory(),
            'user_id' => User::factory(),
            'last_read_reply_id' => null,
        ];
    }

    /**
     * Point the read pointer at a specific reply the user has seen.
     */
    public function upTo(Message $reply): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_read_reply_id' => $reply->id,
        ]);
    }
}
