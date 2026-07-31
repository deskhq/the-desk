<?php

declare(strict_types=1);

use App\Enums\MessageCredentialKind;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\IncomingWebhook;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A channel a bot posts into, plus an integrations admin and an ordinary member
 * who can both read it. The credential behind a row is minted per test — which
 * one produced it is the whole subject here.
 *
 * @return array{team: Team, channel: Channel, bot: User, admin: User, member: User}
 */
function credentialSourceFixture(): array
{
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $bot = User::factory()->bot($team)->create(['name' => 'Deploy Bot']);
    $channel = Channel::factory()->for($team)->create(['name' => 'ops']);
    $channel->channelMembers()->create(['user_id' => $bot->id]);
    $channel->channelMembers()->create(['user_id' => $admin->id]);
    $channel->channelMembers()->create(['user_id' => $member->id]);

    return ['team' => $team, 'channel' => $channel, 'bot' => $bot, 'admin' => $admin, 'member' => $member];
}

/**
 * Open the channel as the given viewer and hand back the timeline assertion.
 */
function visitChannelAs(User $viewer, Team $team, Channel $channel): Assert
{
    $page = null;

    test()->actingAs($viewer)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertOk()
        ->assertInertia(function (Assert $assertable) use (&$page): void {
            $page = $assertable;
        });

    return $page;
}

it('names the API token that produced a message for an integrations admin', function (): void {
    ['team' => $team, 'channel' => $channel, 'bot' => $bot, 'admin' => $admin] = credentialSourceFixture();

    $token = $bot->createToken('CI deploys', ['messages:write']);

    Message::factory()->for($channel)->for($bot, 'user')->create([
        'body' => 'Deployed v2',
        'token_id' => $token->accessToken->getKey(),
    ]);

    visitChannelAs($admin, $team, $channel)
        ->where('messages.data.0.postedVia.kind', MessageCredentialKind::ApiToken->value)
        ->where('messages.data.0.postedVia.id', (string) $token->accessToken->getKey())
        ->where('messages.data.0.postedVia.name', 'CI deploys')
        // The bot whose page holds the rack this credential is revoked from.
        ->where('messages.data.0.postedVia.botId', $bot->id);
});

it('names the incoming webhook that produced a message for an integrations admin', function (): void {
    ['team' => $team, 'channel' => $channel, 'bot' => $bot, 'admin' => $admin] = credentialSourceFixture();

    $webhook = IncomingWebhook::factory()
        ->for($team)->for($channel)->for($bot, 'bot')
        ->create(['name' => 'CI alerts']);

    Message::factory()->for($channel)->for($bot, 'user')->create([
        'body' => 'Deploy finished',
        'incoming_webhook_id' => $webhook->id,
    ]);

    visitChannelAs($admin, $team, $channel)
        ->where('messages.data.0.postedVia.kind', MessageCredentialKind::IncomingWebhook->value)
        ->where('messages.data.0.postedVia.id', $webhook->id)
        ->where('messages.data.0.postedVia.name', 'CI alerts')
        // A webhook's rack is the team-level integrations index, not a bot page.
        ->where('messages.data.0.postedVia.botId', null);
});

it('withholds the credential from a viewer who cannot manage integrations', function (): void {
    ['team' => $team, 'channel' => $channel, 'bot' => $bot, 'member' => $member] = credentialSourceFixture();

    $token = $bot->createToken('CI deploys', ['messages:write']);

    Message::factory()->for($channel)->for($bot, 'user')->create([
        'body' => 'Deployed v2',
        'token_id' => $token->accessToken->getKey(),
    ]);

    // A credential's name is admin-authored and routinely carries operational
    // detail, so it reaches only the people who could act on it.
    visitChannelAs($member, $team, $channel)
        ->where('messages.data.0.postedVia', null);
});

it('withholds a human personal access token even from an integrations admin', function (): void {
    ['team' => $team, 'channel' => $channel, 'admin' => $admin, 'member' => $member] = credentialSourceFixture();

    $token = $member->createToken('my laptop script', ['messages:write']);

    Message::factory()->for($channel)->for($member, 'user')->create([
        'body' => 'Posted from a script',
        'token_id' => $token->accessToken->getKey(),
    ]);

    // A human's token is theirs: only its owner can revoke it, and its name is
    // their own label, so naming it to an admin would be a disclosure with no
    // affordance behind it. The column still records it for support.
    visitChannelAs($admin, $team, $channel)
        ->where('messages.data.0.postedVia', null);
});

it('carries no credential on an ordinary human message', function (): void {
    ['team' => $team, 'channel' => $channel, 'admin' => $admin] = credentialSourceFixture();

    Message::factory()->for($channel)->for($admin, 'user')->create(['body' => 'Nice one']);

    visitChannelAs($admin, $team, $channel)
        ->where('messages.data.0.body', 'Nice one')
        ->where('messages.data.0.postedVia', null);
});
