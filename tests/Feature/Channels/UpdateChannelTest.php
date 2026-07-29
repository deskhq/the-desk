<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\MessageType;
use App\Enums\TeamRole;
use App\Events\ChannelUpdated;
use App\Http\Requests\Channels\UpdateChannelRequest;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/**
 * Build a team with a channel the given roles can be hung off, returning the
 * owner (who also created the channel), the team, and the channel.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function channelToEdit(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $channel = Channel::factory()->for($team)->create([
        'name' => 'Marketing',
        'slug' => 'marketing',
        'created_by' => $owner->id,
    ]);
    $channel->members()->attach($owner->id);

    return [$owner, $team, $channel];
}

/**
 * Add a user to the team and the channel at the given role.
 */
function joinChannelAs(Channel $channel, TeamRole $role): User
{
    $user = User::factory()->create();
    $channel->team->memberships()->create(['user_id' => $user->id, 'role' => $role]);
    $channel->members()->attach($user->id);

    return $user;
}

/**
 * PATCH the channel-edit endpoint as the given user.
 *
 * @param  array<string, mixed>  $payload
 */
function patchChannel(User $actor, Channel $channel, array $payload): TestResponse
{
    return test()->actingAs($actor)->patch(
        route('channels.update', ['team' => $channel->team->slug, 'channel' => $channel->slug]),
        $payload,
    );
}

test('a channel member can set the topic and description', function (): void {
    [, , $channel] = channelToEdit();
    $member = joinChannelAs($channel, TeamRole::Member);

    patchChannel($member, $channel, [
        'topic' => 'Launch coordination',
        'description' => 'Everything about the **launch**, see https://example.com',
    ])->assertRedirect();

    expect($channel->fresh())
        ->topic->toBe('Launch coordination')
        ->description->toBe('Everything about the **launch**, see https://example.com');
});

test('an absent field is left alone and an explicit null clears it', function (): void {
    [$owner, , $channel] = channelToEdit();
    $channel->update(['topic' => 'Campaigns', 'description' => 'The marketing channel.']);

    patchChannel($owner, $channel, ['description' => null])->assertRedirect();

    expect($channel->fresh())
        ->topic->toBe('Campaigns')
        ->description->toBeNull();
});

test('changing the topic posts a system notice carrying the new topic', function (): void {
    [$owner, , $channel] = channelToEdit();

    patchChannel($owner, $channel, ['topic' => 'Launch coordination'])->assertRedirect();

    $notice = Message::where('channel_id', $channel->id)->where('type', MessageType::TopicChanged)->sole();

    expect($notice->body)->toBe('Launch coordination')
        ->and($notice->user_id)->toBe($owner->id)
        ->and($notice->isSystem())->toBeTrue();
});

test('clearing the topic posts a notice with an empty subject', function (): void {
    [$owner, , $channel] = channelToEdit();
    $channel->update(['topic' => 'Campaigns']);

    patchChannel($owner, $channel, ['topic' => null])->assertRedirect();

    expect(Message::where('channel_id', $channel->id)->where('type', MessageType::TopicChanged)->sole()->body)->toBe('');
});

test('resubmitting the same topic posts no notice', function (): void {
    [$owner, , $channel] = channelToEdit();
    $channel->update(['topic' => 'Campaigns']);

    patchChannel($owner, $channel, ['topic' => 'Campaigns'])->assertRedirect();

    expect(Message::where('channel_id', $channel->id)->where('type', MessageType::TopicChanged)->exists())->toBeFalse();
});

test('editing the description is silent in the timeline', function (): void {
    [$owner, , $channel] = channelToEdit();

    patchChannel($owner, $channel, ['description' => 'What this channel is for.'])->assertRedirect();

    expect(Message::where('channel_id', $channel->id)->exists())->toBeFalse();
});

test('the channel creator can rename the channel and a notice records it', function (): void {
    [$owner, , $channel] = channelToEdit();

    patchChannel($owner, $channel, ['name' => '#Growth'])->assertRedirect();

    expect($channel->fresh()->name)->toBe('Growth')
        ->and(Message::where('channel_id', $channel->id)->where('type', MessageType::ChannelRenamed)->sole()->body)->toBe('Growth');
});

test('a rename keeps the channel reachable at its original slug', function (): void {
    [$owner, , $channel] = channelToEdit();

    patchChannel($owner, $channel, ['name' => 'Growth'])->assertRedirect();

    expect($channel->fresh()->slug)->toBe('marketing');
});

test('a team admin who is a member can rename a channel they did not create', function (): void {
    [, , $channel] = channelToEdit();
    $admin = joinChannelAs($channel, TeamRole::Admin);

    patchChannel($admin, $channel, ['name' => 'Growth'])->assertRedirect();

    expect($channel->fresh()->name)->toBe('Growth');
});

test('a plain member cannot rename the channel', function (): void {
    [, , $channel] = channelToEdit();
    $member = joinChannelAs($channel, TeamRole::Member);

    patchChannel($member, $channel, ['name' => 'Growth', 'topic' => 'Launch'])->assertForbidden();

    expect($channel->fresh())->name->toBe('Marketing')->topic->toBeNull();
});

test('a plain member may submit the unchanged name alongside a topic edit', function (): void {
    [, , $channel] = channelToEdit();
    $member = joinChannelAs($channel, TeamRole::Member);

    patchChannel($member, $channel, ['name' => 'Marketing', 'topic' => 'Launch'])->assertRedirect();

    expect($channel->fresh()->topic)->toBe('Launch');
});

test('a team member who is not in the channel cannot edit it', function (): void {
    [, $team, $channel] = channelToEdit();
    $outsider = User::factory()->create();
    $team->memberships()->create(['user_id' => $outsider->id, 'role' => TeamRole::Admin]);

    patchChannel($outsider, $channel, ['topic' => 'Launch'])->assertForbidden();
});

test('an archived channel cannot be edited', function (): void {
    [$owner, , $channel] = channelToEdit();
    $channel->update(['archived_at' => now()]);

    patchChannel($owner, $channel, ['topic' => 'Launch'])->assertForbidden();
});

test('a direct message cannot be edited', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $dm = Channel::factory()->for($team)->direct()->create();
    $dm->members()->attach($owner->id);

    patchChannel($owner, $dm, ['topic' => 'Launch'])->assertForbidden();
});

test('the topic and description are length limited', function (): void {
    [$owner, , $channel] = channelToEdit();

    patchChannel($owner, $channel, [
        'topic' => str_repeat('a', 256),
        'description' => str_repeat('b', UpdateChannelRequest::MAX_DESCRIPTION_LENGTH + 1),
    ])->assertSessionHasErrors(['topic', 'description']);
});

test('a rename cannot collide with another channel in the team', function (): void {
    [$owner, $team, $channel] = channelToEdit();
    Channel::factory()->for($team)->create(['name' => 'Growth', 'slug' => 'growth']);

    patchChannel($owner, $channel, ['name' => 'Growth'])->assertSessionHasErrors('name');

    expect($channel->fresh()->name)->toBe('Marketing');
});

test('an edit broadcasts the channel details to open clients', function (): void {
    Event::fake([ChannelUpdated::class]);
    [$owner, , $channel] = channelToEdit();

    patchChannel($owner, $channel, ['description' => 'What this channel is for.'])->assertRedirect();

    Event::assertDispatched(ChannelUpdated::class, fn (ChannelUpdated $event): bool => $event->channel->is($channel)
        && $event->broadcastWith()['description'] === 'What this channel is for.'
        && $event->broadcastOn()[0]->name === 'private-channel.'.$channel->id);
});

test('the channel page exposes the description and who may edit it', function (): void {
    [, , $channel] = channelToEdit();
    $channel->update(['description' => 'What this channel is for.']);
    $member = joinChannelAs($channel, TeamRole::Member);

    $this->actingAs($member)
        ->get(route('channels.show', ['team' => $channel->team->slug, 'channel' => $channel->slug]))
        ->assertInertia(fn ($page) => $page
            ->where('channel.description', 'What this channel is for.')
            ->where('canEditChannel', true)
            ->where('canRenameChannel', false)
        );
});
