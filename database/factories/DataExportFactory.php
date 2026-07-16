<?php

namespace Database\Factories;

use App\Enums\DataExportStatus;
use App\Models\DataExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataExport>
 */
class DataExportFactory extends Factory
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
            'status' => DataExportStatus::Pending,
            'path' => null,
            'expires_at' => null,
        ];
    }

    /**
     * Mark the export as built and ready to download.
     */
    public function ready(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DataExportStatus::Ready,
            'path' => 'exports/'.fake()->uuid().'.zip',
            'size_bytes' => fake()->numberBetween(1_024, 512 * 1_024 * 1_024),
            'expires_at' => now()->addDays(7),
        ]);
    }

    /**
     * Mark the export as expired (ready, but past its download window).
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DataExportStatus::Ready,
            'path' => 'exports/'.fake()->uuid().'.zip',
            'expires_at' => now()->subDay(),
        ]);
    }

    /**
     * Mark the export as failed to build.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DataExportStatus::Failed,
        ]);
    }
}
