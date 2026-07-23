<?php

namespace Database\Factories;

use App\Enums\AppLocale;
use App\Enums\ChimeSound;
use App\Enums\PresenceState;
use App\Enums\SidebarPosition;
use App\Enums\TeamRole;
use App\Enums\TimeFormat;
use App\Enums\UserType;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'type' => UserType::Human->value,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'locale' => AppLocale::English->value,
            'time_format' => TimeFormat::Auto->value,
            'chime_sound' => ChimeSound::Ping->value,
            'share_read_receipts' => true,
            'sidebar_position' => SidebarPosition::Left->value,
            'presence_state' => PresenceState::Active->value,
            // Factory users are onboarded by default so the first-run tour never
            // auto-fires in unrelated feature/browser tests; opt into the pre-tour
            // state with the notOnboarded() helper below.
            'onboarding_completed_at' => now(),
        ];
    }

    /**
     * Configure the model factory.
     */
    #[\Override]
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            // A bot is team-scoped through its owner_team_id, never a personal
            // team or a team_members row — that absence is what keeps it out of
            // seat counts, member lists, invites, and mention autocomplete.
            if ($user->isBot()) {
                return;
            }

            $team = Team::factory()->personal()->create([
                'name' => $user->name."'s Team",
            ]);

            $team->members()->attach($user, [
                'role' => TeamRole::Owner->value,
            ]);

            $user->switchTeam($team);
        });
    }

    /**
     * Indicate that the user is a bot (a non-human integration identity).
     *
     * A bot has no login password and, when scoped to a team, references it
     * through owner_team_id rather than a team_members pivot row.
     */
    public function bot(?Team $team = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => UserType::Bot->value,
            'password' => null,
            'owner_team_id' => $team?->id,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user has opted out of sharing read receipts.
     */
    public function withoutReadReceipts(): static
    {
        return $this->state(fn (array $attributes): array => [
            'share_read_receipts' => false,
        ]);
    }

    /**
     * Indicate that the user has not yet completed the first-run onboarding tour.
     */
    public function notOnboarded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'onboarding_completed_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes): array => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
