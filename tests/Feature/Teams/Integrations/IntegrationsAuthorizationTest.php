<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\IncomingWebhook;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;

/**
 * A workspace with a plain member in it, and one of everything the integrations
 * surface manages so each verb has a real row to aim at.
 *
 * @return array{member: User, team: Team}
 */
function integrationsAuthorizationFixture(): array
{
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    return ['member' => $member, 'team' => $team];
}

/**
 * Every verb on the integrations surface, paired with a closure that builds the
 * row it acts on inside the actor's own workspace — so the only thing left to
 * refuse the request is the `manageIntegrations` gate.
 */
dataset('integrations verbs', [
    'index' => ['get', 'teams.integrations.index', fn (Team $team): array => [[$team->slug], []]],
    'bot create' => ['post', 'teams.integrations.bots.store', fn (Team $team): array => [[$team->slug], ['name' => 'Deploy Bot']]],
    'bot detail' => ['get', 'teams.integrations.bots.show', fn (Team $team): array => [
        [$team->slug, User::factory()->bot($team)->create()->id], [],
    ]],
    'bot destroy' => ['delete', 'teams.integrations.bots.destroy', fn (Team $team): array => [
        [$team->slug, User::factory()->bot($team)->create()->id], [],
    ]],
    'bot channel add' => ['post', 'teams.integrations.bots.channels.store', fn (Team $team): array => [
        [$team->slug, User::factory()->bot($team)->create()->id],
        ['channel_id' => Channel::factory()->for($team)->create()->id],
    ]],
    'bot channel remove' => ['delete', 'teams.integrations.bots.channels.destroy', function (Team $team): array {
        $bot = User::factory()->bot($team)->create();
        $channel = Channel::factory()->for($team)->create();
        $channel->channelMembers()->create(['user_id' => $bot->id]);

        return [[$team->slug, $bot->id, $channel->id], []];
    }],
    'bot token mint' => ['post', 'teams.integrations.bots.tokens.store', fn (Team $team): array => [
        [$team->slug, User::factory()->bot($team)->create()->id],
        ['name' => 'CI', 'abilities' => ['messages:write']],
    ]],
    'bot token revoke' => ['delete', 'teams.integrations.bots.tokens.destroy', function (Team $team): array {
        $bot = User::factory()->bot($team)->create();
        $token = $bot->createToken('CI', ['messages:write']);

        return [[$team->slug, $bot->id, $token->accessToken->getKey()], []];
    }],
    'incoming webhook create' => ['post', 'teams.integrations.incoming-webhooks.store', function (Team $team): array {
        $bot = User::factory()->bot($team)->create();
        $channel = Channel::factory()->for($team)->create();
        $channel->channelMembers()->create(['user_id' => $bot->id]);

        return [[$team->slug], ['name' => 'CI alerts', 'bot_id' => $bot->id, 'channel_id' => $channel->id]];
    }],
    'incoming webhook revoke' => ['delete', 'teams.integrations.incoming-webhooks.destroy', fn (Team $team): array => [
        [$team->slug, IncomingWebhook::factory()->for($team)->create()->id], [],
    ]],
    'subscription create' => ['post', 'teams.integrations.webhooks.store', fn (Team $team): array => [
        [$team->slug],
        ['name' => 'Audit sink', 'url' => 'https://example.com/hook', 'events' => ['message.created']],
    ]],
    'subscription detail' => ['get', 'teams.integrations.webhooks.show', fn (Team $team): array => [
        [$team->slug, WebhookSubscription::factory()->for($team)->create()->id], [],
    ]],
    'subscription revoke' => ['delete', 'teams.integrations.webhooks.destroy', fn (Team $team): array => [
        [$team->slug, WebhookSubscription::factory()->for($team)->create()->id], [],
    ]],
    'subscription reenable' => ['post', 'teams.integrations.webhooks.reenable', fn (Team $team): array => [
        [$team->slug, WebhookSubscription::factory()->for($team)->create()->id], [],
    ]],
    'subscription secret rotation' => ['post', 'teams.integrations.webhooks.rotate-secret', fn (Team $team): array => [
        [$team->slug, WebhookSubscription::factory()->for($team)->create()->id], [],
    ]],
    'delivery replay' => ['post', 'teams.integrations.webhooks.deliveries.replay', function (Team $team): array {
        $subscription = WebhookSubscription::factory()->for($team)->create();
        $delivery = WebhookDelivery::factory()->for($subscription, 'subscription')->create();

        return [[$team->slug, $subscription->id, $delivery->id], []];
    }],
]);

it('403s every integrations verb for a member who cannot manage integrations', function (string $method, string $name, Closure $build): void {
    ['member' => $member, 'team' => $team] = integrationsAuthorizationFixture();

    [$parameters, $body] = $build($team);

    $this->actingAs($member)
        ->{$method}(route($name, $parameters), $body)
        ->assertForbidden();
})->with('integrations verbs');
