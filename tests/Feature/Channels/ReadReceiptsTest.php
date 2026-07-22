<?php

use App\Actions\Channels\MarkChannelRead;
use App\Actions\Teams\CreateTeam;
use App\Data\UserData;
use App\Enums\TeamRole;
use App\Events\MessageRead;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function receiptsTeamWithGeneral(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

/**
 * Add a user to the team and #general, returning them.
 */
function receiptsMember(Team $team, Channel $channel, User $user): User
{
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);
    $channel->channelMembers()->firstOrCreate(['user_id' => $user->id]);

    return $user;
}

test('MarkChannelRead broadcasts the advance for a user who shares read receipts', function (): void {
    Event::fake([MessageRead::class]);

    [$owner, $team, $general] = receiptsTeamWithGeneral();
    $member = receiptsMember($team, $general, User::factory()->create(['name' => 'Ada Lovelace']));

    Message::factory()->for($general)->for($owner)->create();
    $latest = Message::factory()->for($general)->for($owner)->create();

    app(MarkChannelRead::class)->handle($general, $member);

    Event::assertDispatched(MessageRead::class, fn (MessageRead $event): bool => $event->channel->is($general)
        && $event->reader->id === $member->id
        && $event->reader->name === 'Ada Lovelace'
        && $event->lastReadMessageId === $latest->id);
});

test('MarkChannelRead does not broadcast for a user who opted out of read receipts', function (): void {
    Event::fake([MessageRead::class]);

    [$owner, $team, $general] = receiptsTeamWithGeneral();
    $member = receiptsMember($team, $general, User::factory()->withoutReadReceipts()->create());

    $latest = Message::factory()->for($general)->for($owner)->create();

    app(MarkChannelRead::class)->handle($general, $member);

    // The pointer still advances so their own badges clear...
    $this->assertDatabaseHas('channel_members', [
        'channel_id' => $general->id,
        'user_id' => $member->id,
        'last_read_message_id' => $latest->id,
    ]);

    // ...but nothing is shared with peers.
    Event::assertNotDispatched(MessageRead::class);
});

test('MarkChannelRead does not re-broadcast when the pointer is already at the tail', function (): void {
    Event::fake([MessageRead::class]);

    [$owner, $team, $general] = receiptsTeamWithGeneral();
    $member = receiptsMember($team, $general, User::factory()->create());

    Message::factory()->for($general)->for($owner)->create();

    // First read advances and broadcasts.
    app(MarkChannelRead::class)->handle($general, $member);
    // Second read with no new messages is a no-op.
    app(MarkChannelRead::class)->handle($general, $member);

    Event::assertDispatchedTimes(MessageRead::class, 1);
});

test('MarkChannelRead does not broadcast on an empty channel', function (): void {
    Event::fake([MessageRead::class]);

    [, $team, $general] = receiptsTeamWithGeneral();
    $member = receiptsMember($team, $general, User::factory()->create());

    app(MarkChannelRead::class)->handle($general, $member);

    Event::assertNotDispatched(MessageRead::class);
});

test('MarkChannelRead does not broadcast for a non-member', function (): void {
    Event::fake([MessageRead::class]);

    [$owner, $team, $general] = receiptsTeamWithGeneral();
    $outsider = User::factory()->create();
    $team->memberships()->create(['user_id' => $outsider->id, 'role' => TeamRole::Member]);
    $general->channelMembers()->where('user_id', $outsider->id)->delete();

    Message::factory()->for($general)->for($owner)->create();

    app(MarkChannelRead::class)->handle($general, $outsider);

    Event::assertNotDispatched(MessageRead::class);
});

test('the read endpoint broadcasts the advance for a sharing member', function (): void {
    Event::fake([MessageRead::class]);

    [$owner, $team, $general] = receiptsTeamWithGeneral();
    $member = receiptsMember($team, $general, User::factory()->create());

    $latest = Message::factory()->for($general)->for($owner)->create();

    $this->actingAs($member)
        ->post(route('channels.read', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertRedirect();

    Event::assertDispatched(MessageRead::class, fn (MessageRead $event): bool => $event->lastReadMessageId === $latest->id);
});

test('MessageRead broadcasts on the channel private channel with the reader and pointer', function (): void {
    [$owner, $team, $general] = receiptsTeamWithGeneral();

    $message = Message::factory()->for($general)->for($owner)->create();
    $event = new MessageRead($general, UserData::fromUser($owner), $message->id);

    expect($event->broadcastOn())->toEqual([new PrivateChannel('channel.'.$general->id)]);
    expect($event->broadcastWith())->toEqual([
        'reader' => ['id' => $owner->id, 'name' => $owner->name, 'avatar' => $owner->avatar, 'isBot' => false, 'status' => null, 'presence' => 'active'],
        'lastReadMessageId' => $message->id,
    ]);
});

test('the channel page seeds channelReaders with sharing members, excluding the viewer and opt-outs', function (): void {
    [$owner, $team, $general] = receiptsTeamWithGeneral();
    $viewer = receiptsMember($team, $general, User::factory()->create(['name' => 'Viewer']));
    $sharer = receiptsMember($team, $general, User::factory()->create(['name' => 'Sharer']));
    $optedOut = receiptsMember($team, $general, User::factory()->withoutReadReceipts()->create(['name' => 'Quiet']));

    $message = Message::factory()->for($general)->for($owner)->create();
    $sharer->channels()->updateExistingPivot($general->id, ['last_read_message_id' => $message->id]);

    $response = $this->actingAs($viewer)->get(route('channels.show', [
        'team' => $team->slug,
        'channel' => $general->slug,
    ]))->assertOk();

    $response->assertInertia(fn (Assert $page): Assert => $page
        ->has('channelReaders')
        ->where('channelReaders', fn ($readers): bool => collect($readers)->pluck('user.id')->contains($sharer->id)
            && ! collect($readers)->pluck('user.id')->contains($viewer->id)
            && ! collect($readers)->pluck('user.id')->contains($optedOut->id))
    );

    $entry = collect($response->viewData('page')['props']['channelReaders'])
        ->firstWhere('user.id', $sharer->id);

    expect($entry['lastReadMessageId'])->toBe($message->id);
});
