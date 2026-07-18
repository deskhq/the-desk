<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\IncomingWebhook;
use App\Models\Team;
use App\Models\User;

/**
 * @return array{team: Team, owner: User, member: User, bot: User, channel: Channel}
 */
function incomingFixture(): array
{
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $bot = User::factory()->bot($team)->create(['name' => 'Deploy Bot']);
    $channel = Channel::factory()->for($team)->create(['name' => 'ops']);
    $channel->channelMembers()->create(['user_id' => $bot->id]);

    return ['team' => $team, 'owner' => $owner, 'member' => $member, 'bot' => $bot, 'channel' => $channel];
}

it('creates an incoming webhook bound to the bot and channel', function (): void {
    ['team' => $team, 'owner' => $owner, 'bot' => $bot, 'channel' => $channel] = incomingFixture();

    $this->actingAs($owner)
        ->post(route('teams.integrations.incoming-webhooks.store', $team), [
            'name' => 'CI alerts',
            'channel_id' => $channel->id,
            'bot_id' => $bot->id,
        ])
        ->assertRedirect();

    $webhook = IncomingWebhook::where('team_id', $team->id)->sole();
    expect($webhook->name)->toBe('CI alerts')
        ->and($webhook->bot_id)->toBe($bot->id)
        ->and($webhook->channel_id)->toBe($channel->id);
});

it('rejects an incoming webhook whose bot is not a member of the channel', function (): void {
    ['team' => $team, 'owner' => $owner, 'bot' => $bot] = incomingFixture();
    $otherChannel = Channel::factory()->for($team)->create();

    $this->actingAs($owner)
        ->post(route('teams.integrations.incoming-webhooks.store', $team), [
            'name' => 'Nope',
            'channel_id' => $otherChannel->id,
            'bot_id' => $bot->id,
        ])
        ->assertSessionHasErrors('channel');

    expect(IncomingWebhook::count())->toBe(0);
});

it('rejects an incoming webhook for a channel outside the team', function (): void {
    ['team' => $team, 'owner' => $owner, 'bot' => $bot] = incomingFixture();
    $foreignChannel = Channel::factory()->for(Team::factory()->create())->create();

    $this->actingAs($owner)
        ->post(route('teams.integrations.incoming-webhooks.store', $team), [
            'name' => 'Nope',
            'channel_id' => $foreignChannel->id,
            'bot_id' => $bot->id,
        ])
        ->assertSessionHasErrors('channel_id');
});

it('forbids a member from creating an incoming webhook', function (): void {
    ['team' => $team, 'member' => $member, 'bot' => $bot, 'channel' => $channel] = incomingFixture();

    $this->actingAs($member)
        ->post(route('teams.integrations.incoming-webhooks.store', $team), [
            'name' => 'Nope',
            'channel_id' => $channel->id,
            'bot_id' => $bot->id,
        ])
        ->assertForbidden();
});

it('revokes an incoming webhook', function (): void {
    ['team' => $team, 'owner' => $owner, 'bot' => $bot, 'channel' => $channel] = incomingFixture();
    $webhook = IncomingWebhook::factory()->for($team)->for($bot, 'bot')->for($channel, 'channel')->create();

    $this->actingAs($owner)
        ->delete(route('teams.integrations.incoming-webhooks.destroy', ['team' => $team->slug, 'incomingWebhook' => $webhook->id]))
        ->assertRedirect();

    expect($webhook->fresh()->revoked_at)->not->toBeNull();
});

it('404s revoking an incoming webhook from another team', function (): void {
    ['team' => $team, 'owner' => $owner] = incomingFixture();
    $foreign = IncomingWebhook::factory()->create();

    $this->actingAs($owner)
        ->delete(route('teams.integrations.incoming-webhooks.destroy', ['team' => $team->slug, 'incomingWebhook' => $foreign->id]))
        ->assertNotFound();
});
