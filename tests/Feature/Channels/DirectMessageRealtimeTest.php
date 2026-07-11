<?php

use App\Actions\Channels\OpenDirectMessage;
use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Events\DirectMessageStarted;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * A team owner plus a second member.
 *
 * @return array{0: User, 1: Team, 2: User}
 */
function realtimeTeamWithMember(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $other = User::factory()->create();
    $team->memberships()->create(['user_id' => $other->id, 'role' => TeamRole::Member]);

    return [$owner, $team, $other];
}

/**
 * Switch to a real broadcaster so routes/channels.php authorization runs.
 */
function useRealBroadcasterForDm(): void
{
    config(['broadcasting.default' => 'reverb']);

    require base_path('routes/channels.php');
}

function postDmMessage(User $author, Team $team, Channel $dm, string $body): void
{
    test()->actingAs($author)->post(route('channels.messages.store', [
        'team' => $team->slug,
        'channel' => $dm->slug,
    ]), ['body' => $body, 'client_uuid' => (string) Str::uuid7()]);
}

test('the first message in a direct message announces to the recipient user channel', function () {
    Event::fake([DirectMessageStarted::class]);

    [$owner, $team, $other] = realtimeTeamWithMember();
    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);

    postDmMessage($owner, $team, $dm, 'Hey there');

    Event::assertDispatched(DirectMessageStarted::class, function (DirectMessageStarted $event) use ($dm, $other) {
        $target = $event->broadcastOn()[0];

        expect($target)->toBeInstanceOf(PrivateChannel::class)
            ->and($target->name)->toBe('private-user.'.$other->id);

        return $event->recipientId === $other->id
            && $event->channelId === $dm->id
            && $event->broadcastWith() === ['channelId' => $dm->id];
    });
});

test('only the first direct message announces; later messages do not', function () {
    Event::fake([DirectMessageStarted::class]);

    [$owner, $team, $other] = realtimeTeamWithMember();
    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);

    postDmMessage($owner, $team, $dm, 'first');
    postDmMessage($owner, $team, $dm, 'second');
    postDmMessage($other, $team, $dm, 'third');

    Event::assertDispatchedTimes(DirectMessageStarted::class, 1);
});

test('a self direct message announces to no one', function () {
    Event::fake([DirectMessageStarted::class]);

    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $owner);

    postDmMessage($owner, $team, $dm, 'note to self');

    Event::assertNotDispatched(DirectMessageStarted::class);
});

test('a message in a standard channel never announces a direct message', function () {
    Event::fake([DirectMessageStarted::class]);

    [$owner, $team] = realtimeTeamWithMember();
    $general = Channel::where('team_id', $team->id)->where('slug', Channel::GENERAL_SLUG)->firstOrFail();

    postDmMessage($owner, $team, $general, 'hello channel');

    Event::assertNotDispatched(DirectMessageStarted::class);
});

test('a user may subscribe to their own user channel', function () {
    useRealBroadcasterForDm();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/broadcasting/auth', [
            'channel_name' => 'private-user.'.$user->id,
            'socket_id' => '1234.5678',
        ])
        ->assertOk();
});

test('a user cannot subscribe to another user channel', function () {
    useRealBroadcasterForDm();

    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user)
        ->post('/broadcasting/auth', [
            'channel_name' => 'private-user.'.$other->id,
            'socket_id' => '1234.5678',
        ])
        ->assertForbidden();
});
