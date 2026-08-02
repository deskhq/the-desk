<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\AuditExport;
use App\Models\Channel;
use App\Models\CustomEmoji;
use App\Models\IncomingWebhook;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;

/**
 * An owner of one workspace, plus a second workspace whose rows every case below
 * tries to reach by hanging them off the first workspace's URL.
 *
 * @return array{actor: User, team: Team, other: Team}
 */
function tenancyFixture(): array
{
    $team = Team::factory()->create();
    $other = Team::factory()->create();
    $actor = User::factory()->create();
    $team->members()->attach($actor, ['role' => TeamRole::Owner->value]);

    return ['actor' => $actor, 'team' => $team, 'other' => $other];
}

/**
 * Every `settings/teams/{team}/…` route carrying a nested child, paired with a
 * closure that builds that child outside the workspace in the URL.
 *
 * The actor administers the workspace in the URL, so authorization passes and
 * the only thing left to refuse the request is the binding.
 */
dataset('a child outside the workspace', [
    'audit export download' => ['get', 'teams.audit-exports.download', fn (Team $team, Team $other): array => [[$team->slug, AuditExport::factory()->for($other)->ready()->create()->id], []]],
    'deleted channel restore' => ['post', 'teams.deleted-channels.restore', function (Team $team, Team $other): array {
        $channel = Channel::factory()->for($other)->create();
        $channel->delete();

        return [[$team->slug, $channel->id], []];
    }],
    'custom emoji destroy' => ['delete', 'teams.emojis.destroy', fn (Team $team, Team $other): array => [[$team->slug, CustomEmoji::factory()->for($other)->create()->id], []]],
    'user group update' => ['patch', 'teams.groups.update', fn (Team $team, Team $other): array => [[$team->slug, UserGroup::factory()->for($other)->create()->id], ['name' => 'Hijacked']]],
    'user group destroy' => ['delete', 'teams.groups.destroy', fn (Team $team, Team $other): array => [[$team->slug, UserGroup::factory()->for($other)->create()->id], []]],
    'user group member add' => ['post', 'teams.groups.members.store', fn (Team $team, Team $other, User $actor): array => [[$team->slug, UserGroup::factory()->for($other)->create()->id], ['user_id' => $actor->id]]],
    'user group member remove' => ['delete', 'teams.groups.members.destroy', function (Team $team, Team $other): array {
        $group = UserGroup::factory()->for($other)->create();
        $stranger = User::factory()->create();
        $other->members()->attach($stranger, ['role' => TeamRole::Member->value]);
        $group->members()->attach($stranger);

        return [[$team->slug, $group->id, $stranger->id], []];
    }],
    'member profile page' => ['get', 'teams.members.show', fn (Team $team, Team $other): array => [[$team->slug, teamStranger($other)->id], []]],
    'member profile card' => ['get', 'teams.members.card', fn (Team $team, Team $other): array => [[$team->slug, teamStranger($other)->id], []]],
    'member role update' => ['patch', 'teams.members.update', fn (Team $team, Team $other): array => [[$team->slug, teamStranger($other)->id], ['role' => TeamRole::Admin->value]]],
    'ownership transfer' => ['post', 'teams.members.transfer-ownership', fn (Team $team, Team $other): array => [[$team->slug, teamStranger($other)->id], ['password' => 'password']]],
    'member removal' => ['delete', 'teams.members.destroy', fn (Team $team, Team $other): array => [[$team->slug, teamStranger($other)->id], []]],
    'invitation cancel' => ['delete', 'teams.invitations.destroy', fn (Team $team, Team $other): array => [[$team->slug, TeamInvitation::factory()->for($other)->create()->id], []]],
    'invitation resend' => ['post', 'teams.invitations.resend', fn (Team $team, Team $other): array => [[$team->slug, TeamInvitation::factory()->for($other)->create()->id], []]],
    'bot detail' => ['get', 'teams.integrations.bots.show', fn (Team $team, Team $other): array => [[$team->slug, User::factory()->bot($other)->create()->id], []]],
    'bot destroy' => ['delete', 'teams.integrations.bots.destroy', fn (Team $team, Team $other): array => [[$team->slug, User::factory()->bot($other)->create()->id], []]],
    'bot channel add' => ['post', 'teams.integrations.bots.channels.store', function (Team $team, Team $other): array {
        $channel = Channel::factory()->for($team)->create();

        return [[$team->slug, User::factory()->bot($other)->create()->id], ['channel_id' => $channel->id]];
    }],
    'bot channel remove' => ['delete', 'teams.integrations.bots.channels.destroy', function (Team $team, Team $other): array {
        $bot = User::factory()->bot($other)->create();
        $channel = Channel::factory()->for($other)->create();
        $channel->channelMembers()->create(['user_id' => $bot->id]);

        return [[$team->slug, $bot->id, $channel->id], []];
    }],
    'bot token mint' => ['post', 'teams.integrations.bots.tokens.store', fn (Team $team, Team $other): array => [
        [$team->slug, User::factory()->bot($other)->create()->id],
        ['name' => 'CI', 'abilities' => ['messages:write']],
    ]],
    'bot token revoke' => ['delete', 'teams.integrations.bots.tokens.destroy', function (Team $team, Team $other): array {
        $bot = User::factory()->bot($other)->create();
        $token = $bot->createToken('CI', ['messages:write']);

        return [[$team->slug, $bot->id, $token->accessToken->getKey()], []];
    }],
    'incoming webhook revoke' => ['delete', 'teams.integrations.incoming-webhooks.destroy', fn (Team $team, Team $other): array => [[$team->slug, IncomingWebhook::factory()->for($other)->create()->id], []]],
    'subscription detail' => ['get', 'teams.integrations.webhooks.show', fn (Team $team, Team $other): array => [[$team->slug, WebhookSubscription::factory()->for($other)->create()->id], []]],
    'subscription revoke' => ['delete', 'teams.integrations.webhooks.destroy', fn (Team $team, Team $other): array => [[$team->slug, WebhookSubscription::factory()->for($other)->create()->id], []]],
    'subscription reenable' => ['post', 'teams.integrations.webhooks.reenable', fn (Team $team, Team $other): array => [[$team->slug, WebhookSubscription::factory()->for($other)->create()->id], []]],
    'subscription secret rotation' => ['post', 'teams.integrations.webhooks.rotate-secret', fn (Team $team, Team $other): array => [[$team->slug, WebhookSubscription::factory()->for($other)->create()->id], []]],
    'delivery replay' => ['post', 'teams.integrations.webhooks.deliveries.replay', function (Team $team, Team $other): array {
        $subscription = WebhookSubscription::factory()->for($other)->create();
        $delivery = WebhookDelivery::factory()->for($subscription, 'subscription')->create();

        return [[$team->slug, $subscription->id, $delivery->id], []];
    }],
]);

/**
 * A member of the given workspace, and of no other.
 */
function teamStranger(Team $team): User
{
    $stranger = User::factory()->create();
    $team->members()->attach($stranger, ['role' => TeamRole::Member->value]);

    return $stranger;
}

it('404s a child resource that belongs to another workspace', function (string $method, string $name, Closure $build): void {
    ['actor' => $actor, 'team' => $team, 'other' => $other] = tenancyFixture();

    [$parameters, $body] = $build($team, $other, $actor);

    $this->actingAs($actor)
        ->{$method}(route($name, $parameters), $body)
        ->assertNotFound();
})->with('a child outside the workspace');

it('404s a grandchild that belongs to another parent in the same workspace', function (): void {
    ['actor' => $actor, 'team' => $team] = tenancyFixture();

    $subscription = WebhookSubscription::factory()->for($team)->create();
    $sibling = WebhookSubscription::factory()->for($team)->create();
    $delivery = WebhookDelivery::factory()->for($sibling, 'subscription')->create();

    $this->actingAs($actor)
        ->post(route('teams.integrations.webhooks.deliveries.replay', [$team->slug, $subscription->id, $delivery->id]))
        ->assertNotFound();
});

it('refuses to move a member into a group in a workspace the URL does not name', function (): void {
    // The hole this closes: someone who administers two workspaces used to be
    // able to reach workspace B's group through workspace A's URL, because
    // nothing on the route tied `{userGroup}` to `{team}` and the policy only
    // ever looked at the group's own workspace.
    $first = Team::factory()->create();
    $second = Team::factory()->create();
    $actor = User::factory()->create();
    $first->members()->attach($actor, ['role' => TeamRole::Owner->value]);
    $second->members()->attach($actor, ['role' => TeamRole::Owner->value]);

    $recruit = User::factory()->create();
    $first->members()->attach($recruit, ['role' => TeamRole::Member->value]);

    $group = UserGroup::factory()->for($second)->create();

    $this->actingAs($actor)
        ->post(route('teams.groups.members.store', [$first->slug, $group->id]), ['user_id' => $recruit->id])
        ->assertNotFound();

    expect($group->members()->whereKey($recruit->id)->exists())->toBeFalse();
});
