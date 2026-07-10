<?php

namespace Database\Factories;

use App\Enums\AuditAction;
use App\Models\AuditActivity;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditActivity>
 */
class AuditActivityFactory extends Factory
{
    protected $model = AuditActivity::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $action = fake()->randomElement(AuditAction::cases());

        return [
            'log_name' => 'audit',
            'event' => $action->value,
            'description' => $action->label(),
            'properties' => [],
            'causer_type' => (new User)->getMorphClass(),
            'causer_id' => User::factory(),
            'team_id' => Team::factory(),
        ];
    }

    /**
     * Record the entry for a specific action.
     */
    public function ofAction(AuditAction $action): static
    {
        return $this->state(fn (array $attributes): array => [
            'event' => $action->value,
            'description' => $action->label(),
        ]);
    }

    /**
     * Record the entry within the given workspace.
     */
    public function forTeam(Team $team): static
    {
        return $this->state(fn (array $attributes): array => [
            'team_id' => $team->id,
        ]);
    }

    /**
     * Attribute the entry to a specific actor.
     */
    public function causedBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'causer_type' => $user->getMorphClass(),
            'causer_id' => $user->id,
        ]);
    }
}
