<?php

use App\Data\UserData;
use App\Enums\TeamRole;
use App\Events\UserProfileUpdated;
use App\Models\Team;
use App\Models\User;

test('the serialized author payload carries the dnd flag', function (): void {
    $paused = User::factory()->create(['dnd_until' => now()->addHour()]);
    $free = User::factory()->create();

    expect(UserData::fromUser($paused)->isDnd)->toBeTrue()
        ->and(UserData::fromUser($free)->isDnd)->toBeFalse();
});

test('the author payload never leaks the pause instant or the schedule', function (): void {
    $user = User::factory()->create([
        'dnd_until' => now()->addHour(),
        'dnd_schedule_enabled' => true,
        'dnd_starts_at' => '22:00',
        'dnd_ends_at' => '07:00',
        'dnd_schedule_snoozed_until' => now()->addHours(2),
    ]);

    // Scan the whole serialized tree, not just the top-level keys, so a
    // private field can't hide inside a nested structure.
    $json = (string) json_encode(UserData::fromUser($user)->toArray());

    expect($json)->not->toContain('dndUntil', 'dndScheduleEnabled', 'dndStartsAt', 'dndEndsAt', 'scheduleEnabled', 'startsAt', 'endsAt', 'snoozed', 'Snoozed')
        ->and($json)->not->toContain('22:00', '07:00');
});

test('a user reads their own full dnd configuration, raw columns hidden', function (): void {
    $until = now()->addHour()->startOfSecond();
    $snoozedUntil = now()->addHours(2)->startOfSecond();

    $user = User::factory()->create([
        'dnd_until' => $until,
        'dnd_schedule_enabled' => true,
        'dnd_starts_at' => '22:00',
        'dnd_ends_at' => '07:00',
        'dnd_schedule_snoozed_until' => $snoozedUntil,
    ]);

    $serialized = $user->toArray();

    expect($serialized['dnd']['until'])->toBe($until->toIso8601String())
        ->and($serialized['dnd']['scheduleEnabled'])->toBeTrue()
        ->and($serialized['dnd']['startsAt'])->toBe('22:00')
        ->and($serialized['dnd']['endsAt'])->toBe('07:00')
        ->and($serialized['dnd']['scheduleSnoozedUntil'])->toBe($snoozedUntil->toIso8601String())
        ->and($serialized)->not->toHaveKeys(['dnd_until', 'dnd_schedule_enabled', 'dnd_starts_at', 'dnd_ends_at', 'dnd_schedule_snoozed_until']);
});

test('a lapsed pause reads as absent from the own dnd configuration', function (): void {
    $user = User::factory()->create(['dnd_until' => now()->subMinute()]);

    expect($user->toArray()['dnd']['until'])->toBeNull();
});

test('a lapsed snooze reads as absent from the own dnd configuration', function (): void {
    $user = User::factory()->create(['dnd_schedule_snoozed_until' => now()->subMinute()]);

    expect($user->toArray()['dnd']['scheduleSnoozedUntil'])->toBeNull();
});

test('the hover card names dnd without exposing when it ends', function (): void {
    $viewer = User::factory()->create();
    $member = User::factory()->create([
        'dnd_until' => now()->addHour(),
        'dnd_schedule_enabled' => true,
        'dnd_starts_at' => '22:00',
        'dnd_ends_at' => '07:00',
    ]);
    $team = Team::factory()->create();

    $team->members()->attach($viewer, ['role' => TeamRole::Member->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this->actingAs($viewer)
        ->getJson(route('teams.members.card', [$team, $member]))
        ->assertOk()
        ->assertJsonPath('isDnd', true);

    expect($response->json())->not->toHaveKeys(['dndUntil', 'dnd'])
        ->and($response->content())->not->toContain('22:00', '07:00');
});

test('the profile broadcast carries the dnd flag to teammates', function (): void {
    $user = User::factory()->create(['dnd_until' => now()->addHour()]);

    $payload = (new UserProfileUpdated($user))->broadcastWith();

    expect($payload['user']['isDnd'])->toBeTrue();
});
