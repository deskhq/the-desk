<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

afterEach(function (): void {
    putenv('DEMO_MODE');
    unset($_ENV['DEMO_MODE'], $_SERVER['DEMO_MODE']);
});

/**
 * Attach the user to the team as its owner and make it their current team, the
 * shape every visitor lands in on the public demo.
 */
function ownerOf(User $user, Team $team): void
{
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->update(['current_team_id' => $team->id]);
}

test('demo mode blocks renaming the team', function (): void {
    $this->reloadWithDemoMode(true);

    $user = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Northwind Labs']);
    ownerOf($user, $team);

    $this->actingAs($user)
        ->from(route('teams.edit', $team))
        ->patch(route('teams.update', $team), ['name' => 'Hacked'])
        ->assertRedirect(route('teams.edit', $team));

    expect($team->refresh()->name)->toBe('Northwind Labs');
});

test('demo mode blocks deleting the team', function (): void {
    $this->reloadWithDemoMode(true);

    $user = User::factory()->create();
    $team = Team::factory()->create();
    ownerOf($user, $team);

    $this->actingAs($user)
        ->from(route('teams.edit', $team))
        ->delete(route('teams.destroy', $team))
        ->assertRedirect(route('teams.edit', $team));

    $this->assertDatabaseHas('teams', ['id' => $team->id]);
});

test('demo mode blocks changing the password', function (): void {
    $this->reloadWithDemoMode(true);

    $user = User::factory()->create(['password' => bcrypt('demo-password')]);

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'demo-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])
        ->assertRedirect(route('profile.edit'));

    expect(Hash::check('demo-password', $user->refresh()->password))->toBeTrue();
});

test('demo mode blocks deleting the account', function (): void {
    $this->reloadWithDemoMode(true);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('profile.edit'));

    $this->assertDatabaseHas('users', ['id' => $user->id]);
    $this->assertAuthenticated();
});

test('demo mode blocks revoking other sessions', function (): void {
    $this->reloadWithDemoMode(true);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('appearance.edit'))
        ->delete(route('sessions.destroy-others'))
        ->assertRedirect(route('appearance.edit'));
});

test('demo mode blocks enabling two-factor for JSON callers with a 403', function (): void {
    $this->reloadWithDemoMode(true);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('two-factor.enable'))
        ->assertForbidden();

    expect($user->refresh()->two_factor_secret)->toBeNull();
});

test('demo mode leaves non-destructive settings writable', function (): void {
    $this->reloadWithDemoMode(true);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('timezone.update'), ['timezone' => 'Europe/Paris'])
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->timezone)->toBe('Europe/Paris');
});

test('the same destructive actions succeed when demo mode is off', function (): void {
    $this->reloadWithDemoMode(false);

    $user = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Acme']);
    ownerOf($user, $team);

    $this->actingAs($user)
        ->from(route('teams.edit', $team))
        ->patch(route('teams.update', $team), ['name' => 'Renamed'])
        ->assertRedirect();

    expect($team->refresh()->name)->toBe('Renamed');
});
