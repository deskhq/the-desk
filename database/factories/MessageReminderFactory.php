<?php

namespace Database\Factories;

use App\Enums\MessageReminderStatus;
use App\Models\Message;
use App\Models\MessageReminder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageReminder>
 */
class MessageReminderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'message_id' => Message::factory(),
            'remind_at' => now()->addHour(),
            'status' => MessageReminderStatus::Pending,
            'fired_at' => null,
        ];
    }

    /**
     * Indicate that the reminder's due time has already passed.
     */
    public function due(): static
    {
        return $this->state(fn (array $attributes): array => [
            'remind_at' => now()->subMinute(),
        ]);
    }

    /**
     * Indicate that the reminder has already fired and awaits acknowledgement.
     */
    public function fired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MessageReminderStatus::Fired,
            'fired_at' => now(),
        ]);
    }
}
