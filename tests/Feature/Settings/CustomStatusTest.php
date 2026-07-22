<?php

use App\Data\UserData;
use App\Enums\TeamRole;
use App\Events\UserProfileUpdated;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

test('a user with no status exposes none', function (): void {
    $user = User::factory()->create();

    expect($user->status)->toBeNull();
});

test('a status without an expiry stays live', function (): void {
    $user = User::factory()->create([
        'status_emoji' => '📅',
        'status_text' => 'In a meeting',
        'status_expires_at' => null,
    ]);

    expect($user->status)->not->toBeNull()
        ->and($user->status->emoji)->toBe('📅')
        ->and($user->status->text)->toBe('In a meeting')
        ->and($user->status->expiresAt)->toBeNull();
});

test('a status lapses on read once its expiry passes', function (): void {
    $user = User::factory()->create([
        'status_emoji' => '🚌',
        'status_text' => 'Commuting',
        'status_expires_at' => now()->subMinute(),
    ]);

    expect($user->status)->toBeNull();
});

test('a status still ahead of its expiry is read as live', function (): void {
    $user = User::factory()->create([
        'status_emoji' => '🚌',
        'status_text' => 'Commuting',
        'status_expires_at' => now()->addMinutes(30),
    ]);

    expect($user->status)->not->toBeNull()
        ->and($user->status->expiresAt)->toBe($user->status_expires_at->toIso8601String());
});

test('a lapsed status is absent from the serialized author payload', function (): void {
    $user = User::factory()->create([
        'status_emoji' => '🤒',
        'status_text' => 'Out sick',
        'status_expires_at' => now()->subHour(),
    ]);

    expect(UserData::fromUser($user)->status)->toBeNull();
});

test('a live status rides the serialized author payload', function (): void {
    $user = User::factory()->create([
        'status_emoji' => '🤒',
        'status_text' => 'Out sick',
        'status_expires_at' => now()->addHour(),
    ]);

    expect(UserData::fromUser($user)->status?->emoji)->toBe('🤒');
});

test('a user can set a status with an expiry', function (): void {
    Event::fake([UserProfileUpdated::class]);
    $user = User::factory()->create();
    // The column stores whole seconds, so compare against a whole-second instant.
    $expiresAt = now()->addHour()->startOfSecond();

    $this->actingAs($user)
        ->put(route('status.update'), [
            'emoji' => '📅',
            'text' => 'In a meeting',
            'expires_at' => $expiresAt->toIso8601String(),
        ])
        ->assertRedirect();

    $user->refresh();

    expect($user->status_emoji)->toBe('📅')
        ->and($user->status_text)->toBe('In a meeting')
        ->and($user->status_expires_at->equalTo($expiresAt))->toBeTrue();

    Event::assertDispatched(UserProfileUpdated::class);
});

test('a user can set a status that never clears', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('status.update'), ['emoji' => '🏠', 'text' => 'Working remotely'])
        ->assertRedirect();

    expect($user->refresh()->status_expires_at)->toBeNull();
});

test('a status may carry an emoji with no text', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('status.update'), ['emoji' => '🌴'])
        ->assertRedirect();

    $user->refresh();

    expect($user->status_emoji)->toBe('🌴')
        ->and($user->status_text)->toBeNull();
});

test('setting a status replaces the previous one wholesale', function (): void {
    $user = User::factory()->create([
        'status_emoji' => '📅',
        'status_text' => 'In a meeting',
        'status_expires_at' => now()->addHour(),
    ]);

    $this->actingAs($user)
        ->put(route('status.update'), ['emoji' => '🏠', 'text' => 'Working remotely'])
        ->assertRedirect();

    $user->refresh();

    expect($user->status_emoji)->toBe('🏠')
        ->and($user->status_text)->toBe('Working remotely')
        ->and($user->status_expires_at)->toBeNull();
});

test('a status requires an emoji', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('status.update'), ['text' => 'In a meeting'])
        ->assertSessionHasErrors('emoji');
});

test('a status text is capped at 100 characters', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('status.update'), ['emoji' => '📅', 'text' => str_repeat('a', 101)])
        ->assertSessionHasErrors('text');
});

test('a status expiry already in the past is rejected', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('status.update'), [
            'emoji' => '📅',
            'expires_at' => now()->subMinute()->toIso8601String(),
        ])
        ->assertSessionHasErrors('expires_at');
});

test('a user can clear their status', function (): void {
    Event::fake([UserProfileUpdated::class]);
    $user = User::factory()->create([
        'status_emoji' => '📅',
        'status_text' => 'In a meeting',
        'status_expires_at' => now()->addHour(),
    ]);

    $this->actingAs($user)
        ->delete(route('status.destroy'))
        ->assertRedirect();

    $user->refresh();

    expect($user->status_emoji)->toBeNull()
        ->and($user->status_text)->toBeNull()
        ->and($user->status_expires_at)->toBeNull();

    Event::assertDispatched(UserProfileUpdated::class);
});

test('a guest cannot set a status', function (): void {
    $this->put(route('status.update'), ['emoji' => '📅'])->assertRedirect(route('login'));
});

test('a live status rides the hover card payload', function (): void {
    $viewer = User::factory()->create();
    $member = User::factory()->create([
        'status_emoji' => '📅',
        'status_text' => 'In a meeting',
        'status_expires_at' => now()->addHour(),
    ]);
    $team = Team::factory()->create();

    $team->members()->attach($viewer, ['role' => TeamRole::Member->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($viewer)
        ->getJson(route('teams.members.card', [$team, $member]))
        ->assertOk()
        ->assertJsonPath('status.emoji', '📅')
        ->assertJsonPath('status.text', 'In a meeting');
});

test('a lapsed status is absent from the hover card payload', function (): void {
    $viewer = User::factory()->create();
    $member = User::factory()->create([
        'status_emoji' => '📅',
        'status_text' => 'In a meeting',
        'status_expires_at' => now()->subHour(),
    ]);
    $team = Team::factory()->create();

    $team->members()->attach($viewer, ['role' => TeamRole::Member->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($viewer)
        ->getJson(route('teams.members.card', [$team, $member]))
        ->assertOk()
        ->assertJsonPath('status', null);
});

test('the team roster carries each member status', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create([
        'status_emoji' => '🏠',
        'status_text' => 'Working remotely',
    ]);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($owner)
        ->get(route('teams.edit', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('members', 2)
            ->where(
                'members',
                fn (Collection $members): bool => $members
                    ->firstWhere('id', $member->id)['status']['emoji'] === '🏠'
            )
        );
});

test('the shared auth user prop carries the viewer own live status', function (): void {
    $user = User::factory()->create([
        'status_emoji' => '🌴',
        'status_text' => 'On holiday',
    ]);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('auth.user.status.emoji', '🌴')
            ->where('auth.user.status.text', 'On holiday')
            ->missing('auth.user.status_emoji')
        );
});
