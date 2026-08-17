<?php

declare(strict_types=1);

use App\Actions\Channels\OpenDirectMessage;
use App\Enums\NotificationLevel;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessagePin;
use App\Models\ScheduledMessage;
use App\Models\User;
use App\Support\ChannelPage;
use Database\Factories\ChannelMemberFactory;

/*
|--------------------------------------------------------------------------
| The channel page read-model (#1197)
|--------------------------------------------------------------------------
|
| ADR-0004 moved the channel timeline's *window* out of the controller and left
| its *payload* behind: nineteen query-builder calls, seven `Gate::allows` and
| three synthetic attributes written onto the bound `Channel` so the DTO could
| read them. This is where that payload went, and it is constructed from a
| channel, a viewer and a team — never a `Request` — so every reading below is
| reachable without an HTTP round-trip, the bar `WorkspaceShell` set (ADR-0008)
| and ADR-0012 named.
|
| `tests/Feature/Channels/*` keeps the HTTP half: that `channels/Show` ships
| each of these as a prop. What the readings *hold* is stated here.
|
*/

test('the page is constructible from a channel and a viewer alone', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $page = new ChannelPage($general, $viewer);

    expect($page->channel()->slug)->toBe($general->slug)
        ->and($page->isMember())->toBeTrue()
        ->and($page->lastReadMessageId())->toBeNull()
        ->and($page->memberCount())->toBe(1)
        ->and($page->pins())->toBe(['pins' => [], 'pinCount' => 0])
        ->and($page->botMembers())->toBe([])
        ->and($page->readers())->toBe([])
        ->and($page->scheduledMessages())->toBe([]);
});

test('the channel DTO carries the viewer own membership state without it being written onto the model', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    channelMembership(
        $general,
        $viewer,
        fn (ChannelMemberFactory $factory): ChannelMemberFactory => $factory
            ->muted()
            ->draft('half a thought')
            ->notificationLevel(NotificationLevel::Mentions),
    );

    $channel = Channel::query()->findOrFail($general->id);

    expect(new ChannelPage($channel, $viewer)->channel())
        ->muted->toBeTrue()
        ->notificationLevel->toBe(NotificationLevel::Mentions)
        ->draft->toBe('half a thought')
        // The row was read, not smuggled in: the model itself never learnt any
        // of it, which is the whole point of handing the DTO its membership.
        ->and($channel->getAttribute('muted'))->toBeNull()
        ->and($channel->getAttribute('notification_level'))->toBeNull()
        ->and($channel->getAttribute('draft'))->toBeNull();
});

test('a non-member reading a public channel is not a member and gets the DTO defaults', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    // A public channel the newcomer never joined — joining the team auto-joins
    // #general, so that one would not answer the question.
    $public = Channel::factory()->for($team)->create(['created_by' => $owner->id]);
    $public->members()->attach($owner->id);

    $stranger = User::factory()->create();
    $team->memberships()->create(['user_id' => $stranger->id, 'role' => TeamRole::Member]);

    $page = new ChannelPage($public, $stranger);

    expect($page->isMember())->toBeFalse()
        ->and($page->lastReadMessageId())->toBeNull()
        ->and($page->channel())
        ->muted->toBeFalse()
        ->notificationLevel->toBe(NotificationLevel::All)
        ->draft->toBeNull();
});

test('the read pointer is the viewer own, as a string the client can compare ids with', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $message = Message::factory()->for($general)->for($viewer)->create();

    channelMembership(
        $general,
        $viewer,
        fn (ChannelMemberFactory $factory): ChannelMemberFactory => $factory->state(['last_read_message_id' => $message->id]),
    );

    expect(new ChannelPage($general, $viewer)->lastReadMessageId())->toBe($message->id);
});

test('the capabilities are the seven gates the page draws its controls from', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $member = teamMemberInChannel($general);

    // A standard channel the member belongs to, so the membership-gated controls
    // separate from the ownership-gated ones on one arrange.
    $channel = Channel::factory()->for($team)->create(['created_by' => $owner->id]);
    $channel->members()->attach([$owner->id, $member->id]);

    expect(new ChannelPage($channel, $owner)->capabilities())->toBe([
        'canArchive' => true,
        'canManagePreferences' => true,
        'canEditChannel' => true,
        'canRenameChannel' => true,
        'canDelete' => true,
        'canLeave' => true,
        'canReact' => true,
    ]);

    // #general is the protected channel: it cannot be archived, renamed, deleted
    // or left, however senior the viewer is.
    expect(new ChannelPage($general, $owner)->capabilities())->toBe([
        'canArchive' => false,
        'canManagePreferences' => true,
        'canEditChannel' => true,
        'canRenameChannel' => true,
        'canDelete' => false,
        'canLeave' => false,
        'canReact' => true,
    ]);
});

test('the member count is the humans in the channel, never its bots', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    teamMemberInChannel($general);

    // A bot is an integration identity, not a seat, so it is on the roster and
    // out of the count.
    $bot = User::factory()->bot($team)->create(['name' => 'Deploy Bot']);
    channelMembership($general, $bot);

    $page = new ChannelPage($general, $viewer);

    expect($page->memberCount())->toBe(2)
        ->and(array_column($page->botMembers(), 'name'))->toContain('Deploy Bot');
});

test('the pins and their count are one reading, most-recently-pinned first', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $older = Message::factory()->for($general)->for($viewer)->create();
    $newer = Message::factory()->for($general)->for($viewer)->create();

    MessagePin::factory()->for($older)->for($general)->for($viewer, 'pinnedBy')->create(['created_at' => now()->subMinute()]);
    MessagePin::factory()->for($newer)->for($general)->for($viewer, 'pinnedBy')->create(['created_at' => now()]);

    $pins = new ChannelPage($general, $viewer)->pins();

    expect($pins['pinCount'])->toBe(2)
        ->and(array_column($pins['pins'], 'id'))->toBe([$newer->id, $older->id])
        // The badge counts what the panel lists, by construction, so a pin the
        // panel does not carry can never still be counted.
        ->and($pins['pinCount'])->toBe(count($pins['pins']));
});

test('two pins landing in the same second still list newest first', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $first = Message::factory()->for($general)->for($viewer)->create();
    $second = Message::factory()->for($general)->for($viewer)->create();

    // Same instant, so the timestamp cannot separate them and the tiebreak is
    // the whole answer — it used to run the other way from the panel's order.
    $pinnedAt = now();
    MessagePin::factory()->for($first)->for($general)->for($viewer, 'pinnedBy')->create(['created_at' => $pinnedAt]);
    MessagePin::factory()->for($second)->for($general)->for($viewer, 'pinnedBy')->create(['created_at' => $pinnedAt]);

    expect(array_column(new ChannelPage($general, $viewer)->pins()['pins'], 'id'))
        ->toBe([$second->id, $first->id]);
});

test('a pin whose message has been deleted leaves the panel and the badge together', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $live = Message::factory()->for($general)->for($viewer)->create();
    $deleted = Message::factory()->for($general)->for($viewer)->create();

    MessagePin::factory()->for($live)->for($general)->for($viewer, 'pinnedBy')->create();
    MessagePin::factory()->for($deleted)->for($general)->for($viewer, 'pinnedBy')->create();

    // Deleting a message unpins it, so a surviving pin over a tombstone is only
    // ever a race. It used to be counted by the badge and withheld from the
    // panel, which is the disagreement one reading makes impossible.
    $deleted->delete();

    $pins = new ChannelPage($general, $viewer)->pins();

    expect($pins['pinCount'])->toBe(1)
        ->and(array_column($pins['pins'], 'id'))->toBe([$live->id]);
});

test('a pinned message carries its attribution, so the panel renders it like the timeline', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $pinner = teamMemberInChannel($general, ['name' => 'Ada Lovelace']);
    $message = Message::factory()->for($general)->for($viewer)->create(['body' => 'the pinned one']);
    MessagePin::factory()->for($message)->for($general)->for($pinner, 'pinnedBy')->create();

    $pins = new ChannelPage($general, $viewer)->pins();

    expect($pins['pins'][0]->body)->toBe('the pinned one')
        ->and($pins['pins'][0]->pin?->pinnedBy->name)->toBe('Ada Lovelace');
});

test('a standard channel adds its own bots and nobody else the visit already carries', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $viewer->update(['name' => 'Marie Curie']);

    // A team member, in this channel: mentionable, and already on the workspace
    // roster the shell ships, so the page does not name them a second time.
    teamMemberInChannel($general, ['name' => 'Ada Lovelace']);
    $channel = Channel::factory()->for($team)->create(['created_by' => $viewer->id]);
    $channel->members()->attach($viewer->id);

    $bot = User::factory()->bot($team)->create(['name' => 'Deploy Bot']);
    channelMembership($channel, $bot);

    // A bot that belongs to another channel is not this channel's to list.
    $otherBot = User::factory()->bot($team)->create(['name' => 'Other Bot']);
    channelMembership($general, $otherBot);

    expect(array_column(new ChannelPage($channel, $viewer)->botMembers(), 'name'))
        ->toBe(['Deploy Bot']);
});

test('a direct message adds nobody, its participants riding the channel itself', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $viewer->update(['name' => 'Marie Curie']);

    $other = teamMemberInChannel($general, ['name' => 'Ada Lovelace']);
    teamMemberInChannel($general, ['name' => 'Not In The Conversation']);

    $dm = app(OpenDirectMessage::class)->handle($team, $viewer, $other);
    $page = new ChannelPage($dm, $viewer);

    expect($page->botMembers())->toBe([])
        // The counterpart the client composes the conversation's roster from,
        // and nobody else.
        ->and(array_column($page->channel()->dmParticipants ?? [], 'name'))
        ->toBe(['Ada Lovelace']);
});

test('the readers are the other members who share read receipts', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $message = Message::factory()->for($general)->for($viewer)->create();

    $reader = teamMemberInChannel(
        $general,
        ['name' => 'Ada Lovelace'],
        state: fn (ChannelMemberFactory $factory): ChannelMemberFactory => $factory->state(['last_read_message_id' => $message->id]),
    );

    // Someone who opted out of read receipts is withheld entirely.
    joinTeamAndChannel($general, User::factory()->withoutReadReceipts()->create(['name' => 'Private Pat']));

    // And the viewer never reports their own position back to themselves.
    channelMembership(
        $general,
        $viewer,
        fn (ChannelMemberFactory $factory): ChannelMemberFactory => $factory->state(['last_read_message_id' => $message->id]),
    );

    $readers = new ChannelPage($general, $viewer)->readers();

    expect($readers)->toHaveCount(1)
        ->and($readers[0]->user->name)->toBe('Ada Lovelace')
        ->and($readers[0]->lastReadMessageId)->toBe($message->id)
        ->and($reader->name)->toBe('Ada Lovelace');
});

test('the scheduled messages are the viewer own pending ones, soonest first, quotes resolved', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $quoted = Message::factory()->for($general)->for($viewer)->create(['body' => 'the parent']);

    $later = ScheduledMessage::factory()->for($general)->for($viewer)->create([
        'body' => 'the later one',
        'send_at' => now()->addHours(3),
    ]);
    $sooner = ScheduledMessage::factory()->for($general)->for($viewer)->create([
        'body' => 'the sooner one',
        'send_at' => now()->addHour(),
        'reply_to_id' => $quoted->id,
    ]);

    // Somebody else's schedule, and one of the viewer's already sent: neither is
    // the composer's to offer.
    ScheduledMessage::factory()->for($general)->for(teamMemberInChannel($general))->create();
    ScheduledMessage::factory()->for($general)->for($viewer)->sent()->create();

    $scheduled = new ChannelPage($general, $viewer)->scheduledMessages();

    expect(array_column($scheduled, 'id'))->toBe([$sooner->id, $later->id])
        ->and($scheduled[0]->replyTo?->body)->toBe('the parent')
        ->and($scheduled[1]->replyTo)->toBeNull();
});
