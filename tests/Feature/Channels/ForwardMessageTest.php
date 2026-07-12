<?php

use App\Actions\Channels\OpenDirectMessage;
use App\Actions\Teams\CreateTeam;
use App\Enums\ChannelVisibility;
use App\Enums\TeamRole;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Create a team whose owner is a member of #general plus a second channel, with
 * a message sitting in #general ready to forward.
 *
 * @return array{0: User, 1: Team, 2: Channel, 3: Channel, 4: Message}
 */
function forwardFixture(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    $target = Channel::factory()->for($team)->create(['name' => 'random']);
    $target->members()->attach($owner->id);

    $source = Message::factory()->for($general)->for($owner)->create(['body' => 'the original']);

    return [$owner, $team, $general, $target, $source];
}

function forwardRoute(Team $team, Channel $source, Message $message): string
{
    return route('channels.messages.forward', [
        'team' => $team->slug,
        'channel' => $source->slug,
        'message' => $message->id,
    ]);
}

test('a message can be forwarded into another channel with a note', function (): void {
    [$owner, $team, $general, $target, $source] = forwardFixture();
    $clientUuid = (string) Str::uuid7();

    $this->actingAs($owner)
        ->from(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->post(forwardRoute($team, $general, $source), [
            'body' => 'check this out',
            'client_uuid' => $clientUuid,
            'target_channel_id' => $target->id,
        ])
        ->assertRedirect(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]));

    $this->assertDatabaseHas('messages', [
        'client_uuid' => $clientUuid,
        'channel_id' => $target->id,
        'body' => 'check this out',
        'forwarded_from_id' => $source->id,
    ]);
});

test('a message can be forwarded with no note, leaving an empty body', function (): void {
    [$owner, $team, $general, $target, $source] = forwardFixture();
    $clientUuid = (string) Str::uuid7();

    $this->actingAs($owner)->post(forwardRoute($team, $general, $source), [
        'client_uuid' => $clientUuid,
        'target_channel_id' => $target->id,
    ]);

    $this->assertDatabaseHas('messages', [
        'client_uuid' => $clientUuid,
        'channel_id' => $target->id,
        'body' => '',
        'forwarded_from_id' => $source->id,
    ]);
});

test('the target channel renders a forwarded message with source attribution', function (): void {
    [$owner, $team, $general, $target, $source] = forwardFixture();

    // A mention on the source rides along in the forwarded quote's mentions.
    $mentioned = User::factory()->create();
    $team->memberships()->create(['user_id' => $mentioned->id, 'role' => TeamRole::Member]);
    $source->mentionedUsers()->attach($mentioned->id);

    Message::factory()->for($target)->for($owner)->forwardedFrom($source)->create(['body' => 'fyi']);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $target->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('messages.data.0.body', 'fyi')
            ->where('messages.data.0.forwardedFrom.id', $source->id)
            ->where('messages.data.0.forwardedFrom.body', 'the original')
            ->where('messages.data.0.forwardedFrom.authorName', $owner->name)
            ->where('messages.data.0.forwardedFrom.channelName', $general->name)
            ->where('messages.data.0.forwardedFrom.isDeleted', false)
            ->where('messages.data.0.forwardedFrom.mentions.0.id', $mentioned->id)
        );
});

test('a message forwarded from a direct message carries a null source channel name', function (): void {
    [$owner, $team, $general, $target, $source] = forwardFixture();

    $friend = User::factory()->create();
    $team->memberships()->create(['user_id' => $friend->id, 'role' => TeamRole::Member]);
    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $friend);
    $dmMessage = Message::factory()->for($dm)->for($owner)->create(['body' => 'from the dm']);

    // Forward the DM message into a standard channel; the source has no channel
    // name, so the forwarded quote must carry null rather than blow up.
    Message::factory()->for($target)->for($owner)->forwardedFrom($dmMessage)->create(['body' => 'passing along']);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $target->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('messages.data.0.forwardedFrom.id', $dmMessage->id)
            ->where('messages.data.0.forwardedFrom.channelName', null)
        );
});

test('a forward whose source was later deleted renders a deleted stub', function (): void {
    [$owner, $team, $general, $target, $source] = forwardFixture();

    Message::factory()->for($target)->for($owner)->forwardedFrom($source)->create(['body' => 'fyi']);
    $source->delete();

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $target->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('messages.data.0.forwardedFrom.id', $source->id)
            ->where('messages.data.0.forwardedFrom.isDeleted', true)
            ->where('messages.data.0.forwardedFrom.body', '')
        );
});

test('a deleted forwarded message carries no source reference', function (): void {
    [$owner, $team, $general, $target, $source] = forwardFixture();

    $forward = Message::factory()->for($target)->for($owner)->forwardedFrom($source)->create(['body' => 'fyi']);
    $forward->delete();

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $target->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('messages.data.0.isDeleted', true)
            ->where('messages.data.0.forwardedFrom', null)
        );
});

test('forwarding broadcasts the source quote in the target channel payload', function (): void {
    Event::fake([MessageSent::class]);

    [$owner, $team, $general, $target, $source] = forwardFixture();

    $this->actingAs($owner)->post(forwardRoute($team, $general, $source), [
        'body' => 'broadcast forward',
        'client_uuid' => (string) Str::uuid7(),
        'target_channel_id' => $target->id,
    ]);

    Event::assertDispatched(MessageSent::class, function (MessageSent $event) use ($target, $source, $general): bool {
        $payload = $event->message->toArray();

        return $event->channel->is($target)
            && $payload['forwardedFrom']['id'] === $source->id
            && $payload['forwardedFrom']['body'] === 'the original'
            && $payload['forwardedFrom']['channelName'] === $general->name;
    });
});

test('a message cannot be forwarded to a channel the user is not a member of', function (): void {
    [$owner, $team, $general, $target, $source] = forwardFixture();
    $stranger = Channel::factory()->for($team)->create();

    $this->actingAs($owner)->post(forwardRoute($team, $general, $source), [
        'body' => 'sneaky',
        'client_uuid' => (string) Str::uuid7(),
        'target_channel_id' => $stranger->id,
    ])->assertInvalid(['target_channel_id']);

    expect(Message::where('channel_id', $stranger->id)->count())->toBe(0);
});

test('a message cannot be forwarded to an archived channel', function (): void {
    [$owner, $team, $general, $target, $source] = forwardFixture();
    $archived = Channel::factory()->for($team)->archived()->create();
    $archived->members()->attach($owner->id);

    $this->actingAs($owner)->post(forwardRoute($team, $general, $source), [
        'body' => 'to the vault',
        'client_uuid' => (string) Str::uuid7(),
        'target_channel_id' => $archived->id,
    ])->assertInvalid(['target_channel_id']);
});

test('a message cannot be forwarded to a channel in another team', function (): void {
    [$owner, $team, $general, $target, $source] = forwardFixture();

    $otherOwner = User::factory()->create();
    $otherTeam = app(CreateTeam::class)->handle($otherOwner, 'Other');
    $otherGeneral = Channel::where('team_id', $otherTeam->id)->where('slug', 'general')->firstOrFail();
    $team->memberships()->create(['user_id' => $otherOwner->id, 'role' => TeamRole::Member]);

    // The author belongs to the other team's channel too, but it lives in a
    // different team than the source, so it is not a valid destination.
    $otherGeneral->members()->attach($owner->id);

    $this->actingAs($owner)->post(forwardRoute($team, $general, $source), [
        'body' => 'cross-team',
        'client_uuid' => (string) Str::uuid7(),
        'target_channel_id' => $otherGeneral->id,
    ])->assertInvalid(['target_channel_id']);
});

test('a user cannot forward a message from a private channel they cannot view', function (): void {
    [$owner, $team, $general, $target, $source] = forwardFixture();

    $private = Channel::factory()->for($team)->create(['visibility' => ChannelVisibility::Private]);
    $secret = Message::factory()->for($private)->for($owner)->create();

    $outsider = User::factory()->create();
    $team->memberships()->create(['user_id' => $outsider->id, 'role' => TeamRole::Member]);
    $target->members()->attach($outsider->id);

    $this->actingAs($outsider)->post(forwardRoute($team, $private, $secret), [
        'body' => 'leak',
        'client_uuid' => (string) Str::uuid7(),
        'target_channel_id' => $target->id,
    ])->assertForbidden();
});

test('a deleted source message cannot be forwarded', function (): void {
    [$owner, $team, $general, $target, $source] = forwardFixture();
    $source->delete();

    $this->actingAs($owner)->post(forwardRoute($team, $general, $source), [
        'body' => 'ghost',
        'client_uuid' => (string) Str::uuid7(),
        'target_channel_id' => $target->id,
    ])->assertNotFound();
});

test('forwarding is idempotent on a resent client uuid', function (): void {
    [$owner, $team, $general, $target, $source] = forwardFixture();
    $clientUuid = (string) Str::uuid7();

    $payload = [
        'body' => 'once',
        'client_uuid' => $clientUuid,
        'target_channel_id' => $target->id,
    ];

    $this->actingAs($owner)->post(forwardRoute($team, $general, $source), $payload);
    $this->actingAs($owner)->post(forwardRoute($team, $general, $source), $payload);

    expect(Message::where('client_uuid', $clientUuid)->count())->toBe(1);
});

test('a message can be forwarded into an existing direct message', function (): void {
    [$owner, $team, $general, $target, $source] = forwardFixture();

    $friend = User::factory()->create();
    $team->memberships()->create(['user_id' => $friend->id, 'role' => TeamRole::Member]);
    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $friend);

    $clientUuid = (string) Str::uuid7();

    $this->actingAs($owner)->post(forwardRoute($team, $general, $source), [
        'body' => 'for you',
        'client_uuid' => $clientUuid,
        'target_user_id' => $friend->id,
    ])->assertRedirect();

    $this->assertDatabaseHas('messages', [
        'client_uuid' => $clientUuid,
        'channel_id' => $dm->id,
        'body' => 'for you',
        'forwarded_from_id' => $source->id,
    ]);
});

test('forwarding to a person with no existing direct message opens one', function (): void {
    [$owner, $team, $general, $target, $source] = forwardFixture();

    $friend = User::factory()->create();
    $team->memberships()->create(['user_id' => $friend->id, 'role' => TeamRole::Member]);

    $clientUuid = (string) Str::uuid7();

    $this->actingAs($owner)->post(forwardRoute($team, $general, $source), [
        'body' => 'nice to meet you',
        'client_uuid' => $clientUuid,
        'target_user_id' => $friend->id,
    ])->assertRedirect();

    $dm = Channel::query()->where('team_id', $team->id)->whereNotNull('dm_key')->firstOrFail();

    expect($dm->members()->whereKey($friend->id)->exists())->toBeTrue()
        ->and($dm->members()->whereKey($owner->id)->exists())->toBeTrue();

    $this->assertDatabaseHas('messages', [
        'client_uuid' => $clientUuid,
        'channel_id' => $dm->id,
        'forwarded_from_id' => $source->id,
    ]);
});

test('a message can be forwarded to yourself as a self note', function (): void {
    [$owner, $team, $general, $target, $source] = forwardFixture();
    $clientUuid = (string) Str::uuid7();

    $this->actingAs($owner)->post(forwardRoute($team, $general, $source), [
        'client_uuid' => $clientUuid,
        'target_user_id' => $owner->id,
    ])->assertRedirect();

    $selfDm = Channel::query()->where('team_id', $team->id)->whereNotNull('dm_key')->firstOrFail();

    $this->assertDatabaseHas('messages', [
        'client_uuid' => $clientUuid,
        'channel_id' => $selfDm->id,
        'forwarded_from_id' => $source->id,
    ]);
});

test('a message cannot be forwarded to a non-member of the team', function (): void {
    [$owner, $team, $general, $target, $source] = forwardFixture();
    $outsider = User::factory()->create();

    $this->actingAs($owner)->post(forwardRoute($team, $general, $source), [
        'client_uuid' => (string) Str::uuid7(),
        'target_user_id' => $outsider->id,
    ])->assertInvalid(['target_user_id']);
});

test('a forward must name either a target channel or a target user', function (): void {
    [$owner, $team, $general, $target, $source] = forwardFixture();

    $this->actingAs($owner)->post(forwardRoute($team, $general, $source), [
        'client_uuid' => (string) Str::uuid7(),
    ])->assertInvalid(['target_channel_id']);
});
