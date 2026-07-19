<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\Poll;
use App\Models\PollOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Poll>
 */
class PollFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => Message::factory()->poll(),
            'question' => rtrim(fake()->sentence(), '.').'?',
            'allow_multiple' => false,
            'is_anonymous' => false,
            'closed_at' => null,
        ];
    }

    /**
     * Attach the given option labels to the poll, in order, once it is created.
     *
     * The standard way tests and the seeder give a poll its options: labels map
     * to `position` by their order in the array.
     *
     * @param  list<string>  $labels
     */
    public function withOptions(array $labels): static
    {
        return $this->afterCreating(function (Poll $poll) use ($labels): void {
            foreach ($labels as $position => $label) {
                PollOption::factory()->for($poll)->create([
                    'label' => $label,
                    'position' => $position,
                ]);
            }
        });
    }

    /**
     * Indicate the poll is still open (accepting votes).
     */
    public function open(): static
    {
        return $this->state(fn (array $attributes): array => [
            'closed_at' => null,
        ]);
    }

    /**
     * Indicate the poll has been closed and its tally frozen.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'closed_at' => now(),
        ]);
    }

    /**
     * Indicate the poll accepts a single choice per voter.
     */
    public function singleChoice(): static
    {
        return $this->state(fn (array $attributes): array => [
            'allow_multiple' => false,
        ]);
    }

    /**
     * Indicate the poll accepts multiple choices per voter.
     */
    public function multiChoice(): static
    {
        return $this->state(fn (array $attributes): array => [
            'allow_multiple' => true,
        ]);
    }

    /**
     * Indicate the poll hides its voter roster (counts only).
     */
    public function anonymous(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_anonymous' => true,
        ]);
    }
}
