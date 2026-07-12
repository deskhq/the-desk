<?php

namespace Database\Factories;

use App\Enums\AppLocale;
use App\Enums\ChimeSound;
use App\Enums\TeamRole;
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
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'locale' => AppLocale::English->value,
            'chime_sound' => ChimeSound::Ping->value,
            'share_read_receipts' => true,
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
        return $this->afterCreating(function ($user): void {
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
