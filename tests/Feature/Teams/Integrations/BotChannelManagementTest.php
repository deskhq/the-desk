<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;

/**
 * @return array{team: Team, owner: User, member: User, bot: User}
 */
function botChannelFixture(): array
{
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $bot = User::factory()->bot($team)->create(['name' => 'Deploy Bot']);

    return ['team' => $team, 'owner' => $owner, 'member' => $member, 'bot' => $bot];
}

it('adds a bot to a public channel and audits it', function (): void {
    ['team' => $team, 'owner' => $owner, 'bot' => $bot] = botChannelFixture();
    $channel = Channel::factory()->for($team)->create(['name' => 'engineering']);

    $this->actingAs($owner)
        ->post(route('teams.integrations.bots.channels.store', ['team' => $team->slug, 'bot' => $bot->id]), [
            'channel_id' => $channel->id,
        ])
        ->assertRedirect(route('teams.integrations.bots.show', ['team' => $team->slug, 'bot' => $bot->id]));

    expect($channel->channelMembers()->where('user_id', $bot->id)->exists())->toBeTrue();

    $this->assertDatabaseHas('activity_log', [
        'team_id' => $team->id,
        'event' => AuditAction::ChannelMemberAdded->value,
        'causer_id' => $owner->id,
    ]);
});

it('adds a bot to a private channel', function (): void {
    ['team' => $team, 'owner' => $owner, 'bot' => $bot] = botChannelFixture();
    $channel = Channel::factory()->for($team)->private()->create(['name' => 'secret']);

    $this->actingAs($owner)
        ->post(route('teams.integrations.bots.channels.store', ['team' => $team->slug, 'bot' => $bot->id]), [
            'channel_id' => $channel->id,
        ])
        ->assertRedirect();

    expect($channel->channelMembers()->where('user_id', $bot->id)->exists())->toBeTrue();
});

it('forbids a plain member from adding a bot to a channel', function (): void {
    ['team' => $team, 'member' => $member, 'bot' => $bot] = botChannelFixture();
    $channel = Channel::factory()->for($team)->create();

    $this->actingAs($member)
        ->post(route('teams.integrations.bots.channels.store', ['team' => $team->slug, 'bot' => $bot->id]), [
            'channel_id' => $channel->id,
        ])
        ->assertForbidden();

    expect($channel->channelMembers()->where('user_id', $bot->id)->exists())->toBeFalse();
});

it('404s when the bot belongs to another team', function (): void {
    ['team' => $team, 'owner' => $owner] = botChannelFixture();
    $foreignBot = User::factory()->bot(Team::factory()->create())->create();
    $channel = Channel::factory()->for($team)->create();

    $this->actingAs($owner)
        ->post(route('teams.integrations.bots.channels.store', ['team' => $team->slug, 'bot' => $foreignBot->id]), [
            'channel_id' => $channel->id,
        ])
        ->assertNotFound();
});

it('rejects a channel from another team', function (): void {
    ['team' => $team, 'owner' => $owner, 'bot' => $bot] = botChannelFixture();
    $foreignChannel = Channel::factory()->for(Team::factory()->create())->create();

    $this->actingAs($owner)
        ->post(route('teams.integrations.bots.channels.store', ['team' => $team->slug, 'bot' => $bot->id]), [
            'channel_id' => $foreignChannel->id,
        ])
        ->assertSessionHasErrors('channel_id');

    expect($foreignChannel->channelMembers()->where('user_id', $bot->id)->exists())->toBeFalse();
});

it('rejects a direct-message channel', function (): void {
    ['team' => $team, 'owner' => $owner, 'bot' => $bot] = botChannelFixture();
    $dm = Channel::factory()->for($team)->direct()->create();

    $this->actingAs($owner)
        ->post(route('teams.integrations.bots.channels.store', ['team' => $team->slug, 'bot' => $bot->id]), [
            'channel_id' => $dm->id,
        ])
        ->assertSessionHasErrors('channel_id');
});

it('rejects adding a bot already in the channel', function (): void {
    ['team' => $team, 'owner' => $owner, 'bot' => $bot] = botChannelFixture();
    $channel = Channel::factory()->for($team)->create();
    $channel->channelMembers()->create(['user_id' => $bot->id]);

    $this->actingAs($owner)
        ->post(route('teams.integrations.bots.channels.store', ['team' => $team->slug, 'bot' => $bot->id]), [
            'channel_id' => $channel->id,
        ])
        ->assertSessionHasErrors('channel_id');
});

it('removes a bot from a channel and audits it', function (): void {
    ['team' => $team, 'owner' => $owner, 'bot' => $bot] = botChannelFixture();
    $channel = Channel::factory()->for($team)->create(['name' => 'engineering']);
    $channel->channelMembers()->create(['user_id' => $bot->id]);

    $this->actingAs($owner)
        ->delete(route('teams.integrations.bots.channels.destroy', ['team' => $team->slug, 'bot' => $bot->id, 'channel' => $channel->id]))
        ->assertRedirect(route('teams.integrations.bots.show', ['team' => $team->slug, 'bot' => $bot->id]));

    expect($channel->channelMembers()->where('user_id', $bot->id)->exists())->toBeFalse();

    $this->assertDatabaseHas('activity_log', [
        'team_id' => $team->id,
        'event' => AuditAction::ChannelMemberRemoved->value,
        'causer_id' => $owner->id,
    ]);
});

it('forbids a plain member from removing a bot from a channel', function (): void {
    ['team' => $team, 'member' => $member, 'bot' => $bot] = botChannelFixture();
    $channel = Channel::factory()->for($team)->create();
    $channel->channelMembers()->create(['user_id' => $bot->id]);

    $this->actingAs($member)
        ->delete(route('teams.integrations.bots.channels.destroy', ['team' => $team->slug, 'bot' => $bot->id, 'channel' => $channel->id]))
        ->assertForbidden();

    expect($channel->channelMembers()->where('user_id', $bot->id)->exists())->toBeTrue();
});

it('unblocks creating an incoming webhook for the bot-and-channel pair', function (): void {
    ['team' => $team, 'owner' => $owner, 'bot' => $bot] = botChannelFixture();
    $channel = Channel::factory()->for($team)->create(['name' => 'engineering']);

    // Before the bot is a member, the incoming-webhook create is refused.
    $this->actingAs($owner)
        ->post(route('teams.integrations.incoming-webhooks.store', $team), [
            'name' => 'CI alerts',
            'channel_id' => $channel->id,
            'bot_id' => $bot->id,
        ])
        ->assertSessionHasErrors('bot_id');

    $this->actingAs($owner)
        ->post(route('teams.integrations.bots.channels.store', ['team' => $team->slug, 'bot' => $bot->id]), [
            'channel_id' => $channel->id,
        ]);

    // Once it is, the same create now succeeds.
    $this->actingAs($owner)
        ->post(route('teams.integrations.incoming-webhooks.store', $team), [
            'name' => 'CI alerts',
            'channel_id' => $channel->id,
            'bot_id' => $bot->id,
        ])
        ->assertSessionHasNoErrors();

    expect($team->incomingWebhooks()->where('bot_id', $bot->id)->where('channel_id', $channel->id)->exists())->toBeTrue();
});

it('404s removing from a channel in another team', function (): void {
    ['team' => $team, 'owner' => $owner, 'bot' => $bot] = botChannelFixture();
    $foreignChannel = Channel::factory()->for(Team::factory()->create())->create();

    $this->actingAs($owner)
        ->delete(route('teams.integrations.bots.channels.destroy', ['team' => $team->slug, 'bot' => $bot->id, 'channel' => $foreignChannel->id]))
        ->assertNotFound();
});
