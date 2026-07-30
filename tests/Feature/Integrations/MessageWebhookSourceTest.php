<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\IncomingWebhook;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A channel holding one message posted through an incoming webhook, plus an
 * integrations admin and an ordinary member who can both read it.
 *
 * @return array{team: Team, channel: Channel, webhook: IncomingWebhook, admin: User, member: User}
 */
function webhookSourcedMessage(): array
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

    $webhook = IncomingWebhook::factory()
        ->for($team)->for($channel)->for($bot, 'bot')
        ->create(['name' => 'CI alerts']);

    Message::factory()->for($channel)->for($bot, 'user')->create([
        'body' => 'Deploy finished',
        'incoming_webhook_id' => $webhook->id,
    ]);

    return ['team' => $team, 'channel' => $channel, 'webhook' => $webhook, 'admin' => $admin, 'member' => $member];
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

it('names the webhook that produced a message for an integrations admin', function (): void {
    ['team' => $team, 'channel' => $channel, 'webhook' => $webhook, 'admin' => $admin] = webhookSourcedMessage();

    visitChannelAs($admin, $team, $channel)
        ->where('messages.data.0.incomingWebhook.id', $webhook->id)
        ->where('messages.data.0.incomingWebhook.name', 'CI alerts');
});

it('withholds the webhook name from a viewer who cannot manage integrations', function (): void {
    ['team' => $team, 'channel' => $channel, 'member' => $member] = webhookSourcedMessage();

    // A webhook's name is admin-authored and routinely carries operational
    // detail, so it reaches only the people who could act on it.
    visitChannelAs($member, $team, $channel)
        ->where('messages.data.0.incomingWebhook', null);
});

it('carries no webhook source on an ordinary human message', function (): void {
    ['team' => $team, 'channel' => $channel, 'admin' => $admin] = webhookSourcedMessage();

    Message::factory()->for($channel)->for($admin, 'user')->create(['body' => 'Nice one']);

    visitChannelAs($admin, $team, $channel)
        ->where('messages.data.0.body', 'Nice one')
        ->where('messages.data.0.incomingWebhook', null);
});
