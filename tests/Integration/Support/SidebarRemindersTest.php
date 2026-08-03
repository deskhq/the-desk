<?php

use App\Actions\Channels\CreateChannel;
use App\Actions\Teams\CreateTeam;
use App\Data\MessageReminderData;
use App\Enums\ChannelVisibility;
use App\Enums\MessageReminderStatus;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageReminder;
use App\Models\User;
use App\Support\SidebarReminders;

/*
|--------------------------------------------------------------------------
| The workspace reminder list, driven directly (#1117)
|--------------------------------------------------------------------------
|
| Every claim about *which* reminders the workspace owes the viewer, in what
| order, and how much of each one they are still allowed to see belongs here:
| `SidebarReminders` is constructible, so there is no reason to render
| `channels/Show` and pluck one prop out of 44 to read it.
| `tests/Feature/Channels/MessageReminderListTest.php` keeps the HTTP half —
| that the page ships `reminders` and `firedReminders` at all.
|
| Nothing here signs in, and that is the point of the redaction tests in
| particular: the read-model's viewer is the only viewer there is, and the
| `view` gate is re-checked against exactly that viewer on every read, so
| losing and regaining access can be stated without a session.
|
*/

/**
 * A reminder the viewer set on a fresh message in the channel.
 *
 * The fired pair (`status` and `fired_at`) comes from the factory's own state
 * rather than an attribute array, so the two cannot be set to disagree here.
 * A null `$remindAt` leaves the factory's own due time standing, for the tests
 * that are not about ordering.
 */
function remindOn(
    Channel $channel,
    User $viewer,
    MessageReminderStatus $status = MessageReminderStatus::Pending,
    ?DateTimeInterface $remindAt = null,
    string $body = 'remember this',
): MessageReminder {
    $message = Message::factory()->for($channel)->for($viewer)->create(['body' => $body]);

    $reminder = MessageReminder::factory()->for($message)->for($viewer);

    if ($status === MessageReminderStatus::Fired) {
        $reminder = $reminder->fired();
    }

    return $reminder->create($remindAt instanceof DateTimeInterface ? ['remind_at' => $remindAt] : []);
}

/**
 * The reminders the read-model listed, in the order it listed them.
 *
 * @param  array<int, MessageReminderData>  $rows
 * @return array<int, string>
 */
function reminderIds(array $rows): array
{
    return array_map(fn (MessageReminderData $row): string => $row->id, $rows);
}

test('reminders come back for the asked-for status only, soonest first', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $later = remindOn($general, $viewer, remindAt: now()->addHours(2), body: 'later');
    $sooner = remindOn($general, $viewer, remindAt: now()->addHour(), body: 'sooner');
    $fired = remindOn($general, $viewer, MessageReminderStatus::Fired, now()->subHour(), 'fired');

    $reminders = new SidebarReminders($viewer, $team);

    expect(reminderIds($reminders->withStatus(MessageReminderStatus::Pending)))
        ->toBe([$sooner->id, $later->id])
        ->and(reminderIds($reminders->withStatus(MessageReminderStatus::Fired)))
        ->toBe([$fired->id]);
});

test('a row carries the message, its author, and the link back to it', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $reminder = remindOn($general, $viewer, remindAt: now()->addHour(), body: 'the sooner one');

    expect(new SidebarReminders($viewer, $team)->withStatus(MessageReminderStatus::Pending)[0])
        ->id->toBe($reminder->id)
        ->messageId->toBe($reminder->message_id)
        ->body->toBe('the sooner one')
        ->authorName->toBe($viewer->name)
        ->teamSlug->toBe($team->slug)
        ->channelSlug->toBe($general->slug)
        ->channelName->toBe($general->name)
        ->isDeleted->toBeFalse()
        ->isAccessible->toBeTrue();
});

test('reminders are scoped to the team and to the viewer', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $mine = remindOn($general, $viewer, remindAt: now()->addHour());

    // The viewer's reminder in another workspace of theirs.
    $other = app(CreateTeam::class)->handle($viewer, 'Other');
    $elsewhere = Channel::query()->where('team_id', $other->id)->where('slug', Channel::GENERAL_SLUG)->firstOrFail();
    remindOn($elsewhere, $viewer, remindAt: now()->addHour());

    // Somebody else's reminder in this workspace.
    remindOn($general, teamMemberInChannel($general), remindAt: now()->addHour());

    expect(reminderIds(new SidebarReminders($viewer, $team)->withStatus(MessageReminderStatus::Pending)))
        ->toBe([$mine->id]);
});

test('a reminder on a since-deleted message blanks its body but keeps the link back', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $reminder = remindOn($general, $viewer, body: 'secret plan');
    $reminder->message->delete();

    expect(new SidebarReminders($viewer, $team)->withStatus(MessageReminderStatus::Pending)[0])
        ->isDeleted->toBeTrue()
        ->body->toBe('')
        ->messageId->toBe($reminder->message_id)
        ->channelSlug->toBe($general->slug);
});

test('a reminder on a channel the viewer can no longer see is redacted to a stub, not dropped', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $private = app(CreateChannel::class)->handle($team, 'war-room', ChannelVisibility::Private, $viewer);
    $reminder = remindOn($private, $viewer, body: 'classified');

    expect(new SidebarReminders($viewer, $team)->withStatus(MessageReminderStatus::Pending)[0])
        ->isAccessible->toBeTrue()
        ->body->toBe('classified');

    $private->members()->detach($viewer->id);

    $redacted = new SidebarReminders($viewer, $team)->withStatus(MessageReminderStatus::Pending);

    expect($redacted)->toHaveCount(1)
        ->and($redacted[0])
        ->id->toBe($reminder->id)
        ->isAccessible->toBeFalse()
        ->body->toBe('')
        ->authorName->toBe('')
        ->channelName->toBeNull()
        ->channelSlug->toBe('')
        // The row survives with its identity intact, so clearing it stays the
        // owner's call even while the channel behind it is out of reach.
        ->messageId->toBe($reminder->message_id)
        ->teamSlug->toBe($team->slug);
});

test('regaining access to the channel restores the reminder intact', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $private = app(CreateChannel::class)->handle($team, 'war-room', ChannelVisibility::Private, $viewer);
    remindOn($private, $viewer, body: 'secret plan');

    $private->members()->detach($viewer->id);

    expect(new SidebarReminders($viewer, $team)->withStatus(MessageReminderStatus::Pending)[0])
        ->isAccessible->toBeFalse();

    channelMembership($private, $viewer);

    expect(new SidebarReminders($viewer, $team)->withStatus(MessageReminderStatus::Pending)[0])
        ->isAccessible->toBeTrue()
        ->body->toBe('secret plan')
        ->authorName->toBe($viewer->name)
        ->channelName->toBe('war-room');
});

test('a reminder in an archived channel stays fully visible', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $archived = app(CreateChannel::class)->handle($team, 'retro', ChannelVisibility::Public, $viewer);
    $reminder = remindOn($archived, $viewer, body: 'still readable');
    $archived->update(['archived_at' => now()]);

    $rows = new SidebarReminders($viewer, $team)->withStatus(MessageReminderStatus::Pending);

    expect($rows)->toHaveCount(1)
        ->and($rows[0])
        ->id->toBe($reminder->id)
        ->isAccessible->toBeTrue()
        ->body->toBe('still readable')
        ->channelName->toBe('retro');
});

test('a fired reminder is redacted the same way as a pending one', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $private = app(CreateChannel::class)->handle($team, 'war-room', ChannelVisibility::Private, $viewer);
    remindOn($private, $viewer, MessageReminderStatus::Fired, now()->subHour(), 'secret plan');

    $private->members()->detach($viewer->id);

    $fired = new SidebarReminders($viewer, $team)->withStatus(MessageReminderStatus::Fired);

    expect($fired)->toHaveCount(1)
        ->and($fired[0])
        ->isAccessible->toBeFalse()
        ->body->toBe('');
});
