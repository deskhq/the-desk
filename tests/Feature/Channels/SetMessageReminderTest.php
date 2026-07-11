<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\MessageReminderStatus;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageReminder;
use App\Models\Team;
use App\Models\User;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function reminderTeamWithGeneral(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

test('a member can set a reminder on a message they can see', function () {
    [$owner, $team, $general] = reminderTeamWithGeneral();
    $message = Message::factory()->for($general)->for($owner)->create();
    $remindAt = now()->addHour();

    $this->actingAs($owner)
        ->post(route('channels.reminders.store', ['team' => $team->slug]), [
            'message_id' => $message->id,
            'remind_at' => $remindAt->toIso8601String(),
        ])
        ->assertRedirect();

    $reminder = MessageReminder::firstOrFail();

    expect($reminder->user_id)->toBe($owner->id)
        ->and($reminder->message_id)->toBe($message->id)
        ->and($reminder->status)->toBe(MessageReminderStatus::Pending)
        ->and($reminder->fired_at)->toBeNull()
        ->and($reminder->remind_at->toIso8601String())->toBe($remindAt->toIso8601String());
});

test('setting a reminder again re-arms the same row rather than stacking', function () {
    [$owner, $team, $general] = reminderTeamWithGeneral();
    $message = Message::factory()->for($general)->for($owner)->create();
    $existing = MessageReminder::factory()->for($owner)->for($message)->fired()->create([
        'remind_at' => now()->subHour(),
    ]);
    $newTime = now()->addHours(2);

    $this->actingAs($owner)
        ->post(route('channels.reminders.store', ['team' => $team->slug]), [
            'message_id' => $message->id,
            'remind_at' => $newTime->toIso8601String(),
        ]);

    expect(MessageReminder::count())->toBe(1);

    $existing->refresh();

    expect($existing->status)->toBe(MessageReminderStatus::Pending)
        ->and($existing->fired_at)->toBeNull()
        ->and($existing->remind_at->toIso8601String())->toBe($newTime->toIso8601String());
});

test('a non-future remind time is rejected', function () {
    [$owner, $team, $general] = reminderTeamWithGeneral();
    $message = Message::factory()->for($general)->for($owner)->create();

    $this->actingAs($owner)
        ->post(route('channels.reminders.store', ['team' => $team->slug]), [
            'message_id' => $message->id,
            'remind_at' => now()->subMinute()->toIso8601String(),
        ])
        ->assertInvalid(['remind_at']);

    expect(MessageReminder::count())->toBe(0);
});

test('a missing remind time is rejected', function () {
    [$owner, $team, $general] = reminderTeamWithGeneral();
    $message = Message::factory()->for($general)->for($owner)->create();

    $this->actingAs($owner)
        ->post(route('channels.reminders.store', ['team' => $team->slug]), [
            'message_id' => $message->id,
        ])
        ->assertInvalid(['remind_at']);
});

test('a reminder cannot target a deleted message', function () {
    [$owner, $team, $general] = reminderTeamWithGeneral();
    $message = Message::factory()->for($general)->for($owner)->create();
    $message->delete();

    // A since-deleted message no longer resolves, so it can neither be seen nor
    // reminded about — the request is refused before it can be stored.
    $this->actingAs($owner)
        ->post(route('channels.reminders.store', ['team' => $team->slug]), [
            'message_id' => $message->id,
            'remind_at' => now()->addHour()->toIso8601String(),
        ])
        ->assertForbidden();

    expect(MessageReminder::count())->toBe(0);
});

test('a user cannot set a reminder on a message in a channel they cannot see', function () {
    [$owner, $team, $general] = reminderTeamWithGeneral();
    $private = Channel::factory()->for($team)->private()->create();
    $private->channelMembers()->create(['user_id' => $owner->id]);
    $message = Message::factory()->for($private)->for($owner)->create();

    $stranger = User::factory()->create();
    $team->memberships()->create(['user_id' => $stranger->id, 'role' => TeamRole::Member]);

    $this->actingAs($stranger)
        ->post(route('channels.reminders.store', ['team' => $team->slug]), [
            'message_id' => $message->id,
            'remind_at' => now()->addHour()->toIso8601String(),
        ])
        ->assertForbidden();

    expect(MessageReminder::count())->toBe(0);
});

test('a reminder cannot target a message in a different team', function () {
    [$owner, $team, $general] = reminderTeamWithGeneral();

    $otherOwner = User::factory()->create();
    $otherTeam = app(CreateTeam::class)->handle($otherOwner, 'Beta');
    $otherGeneral = Channel::where('team_id', $otherTeam->id)->where('slug', 'general')->firstOrFail();
    $foreign = Message::factory()->for($otherGeneral)->for($otherOwner)->create();

    $this->actingAs($owner)
        ->post(route('channels.reminders.store', ['team' => $team->slug]), [
            'message_id' => $foreign->id,
            'remind_at' => now()->addHour()->toIso8601String(),
        ])
        ->assertForbidden();

    expect(MessageReminder::count())->toBe(0);
});
