<?php

use App\Actions\Channels\CreateChannel;
use App\Actions\Teams\CreateTeam;
use App\Data\MessageReminderData;
use App\Enums\ChannelVisibility;
use App\Enums\MessageReminderStatus;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageReminder;
use App\Models\Team;
use App\Models\User;
use App\Support\SidebarReminders;

/**
 * A viewer, their team, and its #general channel. The read-model is driven
 * straight against these — no HTTP round-trip.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function reminderWorkspace(): array
{
    $viewer = User::factory()->create();
    $team = app(CreateTeam::class)->handle($viewer, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', Channel::GENERAL_SLUG)->firstOrFail();

    return [$viewer, $team, $general];
}

/**
 * Set a reminder for the viewer on a fresh message in the channel.
 */
function remindOn(Channel $channel, User $viewer, MessageReminderStatus $status, string $remindAt, string $body = 'remember this'): MessageReminder
{
    $message = Message::factory()->for($channel)->for($viewer)->create(['body' => $body]);

    return MessageReminder::factory()->for($message)->for($viewer)->create([
        'status' => $status,
        'remind_at' => $remindAt,
        'fired_at' => $status === MessageReminderStatus::Fired ? now() : null,
    ]);
}

test('reminders come back for the asked-for status only, soonest first', function (): void {
    [$viewer, $team, $general] = reminderWorkspace();

    $later = remindOn($general, $viewer, MessageReminderStatus::Pending, now()->addHours(2)->toDateTimeString(), 'later');
    $sooner = remindOn($general, $viewer, MessageReminderStatus::Pending, now()->addHour()->toDateTimeString(), 'sooner');
    $fired = remindOn($general, $viewer, MessageReminderStatus::Fired, now()->subHour()->toDateTimeString(), 'fired');

    $reminders = new SidebarReminders($viewer, $team);

    expect(array_map(fn (MessageReminderData $row): string => $row->id, $reminders->withStatus(MessageReminderStatus::Pending)))
        ->toBe([$sooner->id, $later->id])
        ->and(array_map(fn (MessageReminderData $row): string => $row->id, $reminders->withStatus(MessageReminderStatus::Fired)))
        ->toBe([$fired->id]);
});

test('reminders are scoped to the team and to the viewer', function (): void {
    [$viewer, $team, $general] = reminderWorkspace();

    $mine = remindOn($general, $viewer, MessageReminderStatus::Pending, now()->addHour()->toDateTimeString());

    // The viewer's reminder in another workspace of theirs.
    $other = app(CreateTeam::class)->handle($viewer, 'Other');
    $elsewhere = Channel::where('team_id', $other->id)->where('slug', Channel::GENERAL_SLUG)->firstOrFail();
    remindOn($elsewhere, $viewer, MessageReminderStatus::Pending, now()->addHour()->toDateTimeString());

    // Somebody else's reminder in this workspace.
    $mate = User::factory()->create();
    $team->memberships()->create(['user_id' => $mate->id, 'role' => TeamRole::Member]);
    remindOn($general, $mate, MessageReminderStatus::Pending, now()->addHour()->toDateTimeString());

    expect(array_map(
        fn (MessageReminderData $row): string => $row->id,
        new SidebarReminders($viewer, $team)->withStatus(MessageReminderStatus::Pending),
    ))->toBe([$mine->id]);
});

test('a reminder on a channel the viewer can no longer see is redacted, not dropped', function (): void {
    [$viewer, $team] = reminderWorkspace();

    $private = app(CreateChannel::class)->handle($team, 'secrets', ChannelVisibility::Private, $viewer);
    $reminder = remindOn($private, $viewer, MessageReminderStatus::Pending, now()->addHour()->toDateTimeString(), 'classified');

    expect(new SidebarReminders($viewer, $team)->withStatus(MessageReminderStatus::Pending)[0])
        ->isAccessible->toBeTrue()
        ->body->toBe('classified');

    $private->members()->detach($viewer->id);

    $redacted = new SidebarReminders($viewer, $team)->withStatus(MessageReminderStatus::Pending);

    expect($redacted)->toHaveCount(1)
        ->and($redacted[0])->id->toBe($reminder->id)
        ->isAccessible->toBeFalse()
        ->body->not->toBe('classified');
});
