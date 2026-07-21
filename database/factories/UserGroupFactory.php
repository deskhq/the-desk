<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\UserGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UserGroup>
 */
class UserGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'team_id' => Team::factory(),
            'name' => Str::title($name),
            'slug' => Str::slug($name),
        ];
    }

    /**
     * Indicate the group uses a specific handle, with a matching display name.
     */
    public function slug(string $slug): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => Str::headline($slug),
            'slug' => $slug,
        ]);
    }
}
