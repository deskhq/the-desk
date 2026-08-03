<?php

use App\Actions\Channels\MarkChannelRead;
use App\Actions\Channels\OpenDirectMessage;
use App\Enums\TeamRole;
use App\Events\MessageRead;
use App\Events\ReadStateAdvanced;
use App\Models\Message;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;

test('advancing the read pointer signals the reader other devices', function (): void {
    Event::fake([ReadStateAdvanced::class]);

    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    Message::factory()->for($general)->for($owner)->create();

    app(MarkChannelRead::class)->handle($general, $member);

    Event::assertDispatched(ReadStateAdvanced::class, fn (ReadStateAdvanced $event): bool => $event->userId === $member->id
        && $event->channelId === $general->id);
});

test('a reader who hides read receipts still syncs their own devices', function (): void {
    Event::fake([ReadStateAdvanced::class, MessageRead::class]);

    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = joinTeamAndChannel($general, User::factory()->withoutReadReceipts()->create());

    Message::factory()->for($general)->for($owner)->create();

    app(MarkChannelRead::class)->handle($general, $member);

    // Their own devices still learn the channel is read...
    Event::assertDispatched(ReadStateAdvanced::class);
    // ...while peers learn nothing, exactly as the preference promises.
    Event::assertNotDispatched(MessageRead::class);
});

test('a read that advances nothing signals nothing', function (): void {
    Event::fake([ReadStateAdvanced::class]);

    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    Message::factory()->for($general)->for($owner)->create();

    // The client re-marks the open channel read on every arrival and every
    // window focus, so the no-op path is the common one; it must stay silent
    // or every focus would storm the reader's other devices with reloads.
    app(MarkChannelRead::class)->handle($general, $member);
    app(MarkChannelRead::class)->handle($general, $member);

    Event::assertDispatchedTimes(ReadStateAdvanced::class, 1);
});

test('a read with nothing to point at signals nothing', function (): void {
    Event::fake([ReadStateAdvanced::class]);

    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);
    $outsider = User::factory()->create();
    $team->memberships()->create(['user_id' => $outsider->id, 'role' => TeamRole::Member]);
    $general->channelMembers()->where('user_id', $outsider->id)->delete();

    // An empty channel leaves the pointer untouched.
    app(MarkChannelRead::class)->handle($general, $member);

    Message::factory()->for($general)->for($owner)->create();

    // A non-member has no pivot row to advance.
    app(MarkChannelRead::class)->handle($general, $outsider);

    Event::assertNotDispatched(ReadStateAdvanced::class);
});

test('the read endpoint signals the reader other devices for a channel', function (): void {
    Event::fake([ReadStateAdvanced::class]);

    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    Message::factory()->for($general)->for($owner)->create();

    $this->actingAs($member)
        ->post(route('channels.read', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertRedirect();

    Event::assertDispatched(ReadStateAdvanced::class, fn (ReadStateAdvanced $event): bool => $event->userId === $member->id
        && $event->channelId === $general->id);
});

test('the read endpoint signals the reader other devices for a direct message', function (): void {
    Event::fake([ReadStateAdvanced::class]);

    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $member);
    Message::factory()->for($dm)->for($owner)->create();

    $this->actingAs($member)
        ->post(route('channels.read', ['team' => $team->slug, 'channel' => $dm->slug]))
        ->assertRedirect();

    Event::assertDispatched(ReadStateAdvanced::class, fn (ReadStateAdvanced $event): bool => $event->userId === $member->id
        && $event->channelId === $dm->id);
});

test('the signal skips the very device that did the reading', function (): void {
    Event::fake([ReadStateAdvanced::class]);

    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    Message::factory()->for($general)->for($owner)->create();

    // The reading tab sends its Echo socket id, and that connection already
    // gets fresh counts in this request's own response — echoing the signal
    // back to it would only buy a second, redundant sidebar reload.
    $this->actingAs($member)
        ->withHeader('X-Socket-ID', 'reading-device-socket')
        ->post(route('channels.read', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertRedirect();

    Event::assertDispatched(
        ReadStateAdvanced::class,
        fn (ReadStateAdvanced $event): bool => $event->socket === 'reading-device-socket',
    );
});

test('ReadStateAdvanced broadcasts the channel id on the reader own private channel', function (): void {
    $event = new ReadStateAdvanced('user-id', 'channel-id');

    expect($event->broadcastOn())->toEqual([new PrivateChannel('user.user-id')]);
    expect($event->broadcastWith())->toEqual(['channelId' => 'channel-id']);
});
