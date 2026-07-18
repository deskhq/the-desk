<?php

declare(strict_types=1);

use App\Actions\Integrations\CreateBot;
use App\Enums\IntegrationScope;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{team: Team, owner: User, member: User}
 */
function botManagementFixture(): array
{
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    return ['team' => $team, 'owner' => $owner, 'member' => $member];
}

it('creates a bot and redirects to its detail page', function (): void {
    ['team' => $team, 'owner' => $owner] = botManagementFixture();

    $response = $this->actingAs($owner)
        ->post(route('teams.integrations.bots.store', $team), ['name' => 'Deploy Bot']);

    $bot = $team->bots()->sole();
    expect($bot->name)->toBe('Deploy Bot');
    $response->assertRedirect(route('teams.integrations.bots.show', ['team' => $team->slug, 'bot' => $bot->id]));
});

it('forbids a member from creating a bot', function (): void {
    ['team' => $team, 'member' => $member] = botManagementFixture();

    $this->actingAs($member)
        ->post(route('teams.integrations.bots.store', $team), ['name' => 'Nope'])
        ->assertForbidden();

    expect($team->bots()->count())->toBe(0);
});

it('shows a bot detail with its counts, creator, last-post time, tokens, and scopes', function (): void {
    ['team' => $team, 'owner' => $owner] = botManagementFixture();
    // Create through the action so the bot carries a creator; give it a channel
    // membership and a posted message so the counts and last-post time resolve.
    $bot = app(CreateBot::class)->handle($team, $owner, 'Deploy Bot');
    $channel = Channel::factory()->for($team)->create();
    $channel->channelMembers()->create(['user_id' => $bot->id]);
    Message::factory()->for($channel)->for($bot)->create();
    $bot->createToken('ci', [IntegrationScope::MessagesWrite->value]);

    $this->actingAs($owner)
        ->get(route('teams.integrations.bots.show', ['team' => $team->slug, 'bot' => $bot->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('teams/integrations/Bot')
            ->where('bot.name', 'Deploy Bot')
            ->where('bot.channelsCount', 1)
            ->where('bot.tokensCount', 1)
            ->where('bot.createdBy.name', $owner->name)
            ->whereNot('bot.lastPostedAt', null)
            ->has('tokens', 1)
            ->has('scopeOptions', 9)
        );
});

it('404s a bot that belongs to another team', function (): void {
    ['team' => $team, 'owner' => $owner] = botManagementFixture();
    $otherBot = User::factory()->bot(Team::factory()->create())->create();

    $this->actingAs($owner)
        ->get(route('teams.integrations.bots.show', ['team' => $team->slug, 'bot' => $otherBot->id]))
        ->assertNotFound();
});

it('404s when the resolved user is not a bot', function (): void {
    ['team' => $team, 'owner' => $owner, 'member' => $member] = botManagementFixture();

    $this->actingAs($owner)
        ->get(route('teams.integrations.bots.show', ['team' => $team->slug, 'bot' => $member->id]))
        ->assertNotFound();
});

it('deletes a bot and redirects to the integrations home', function (): void {
    ['team' => $team, 'owner' => $owner] = botManagementFixture();
    $bot = User::factory()->bot($team)->create();

    $this->actingAs($owner)
        ->delete(route('teams.integrations.bots.destroy', ['team' => $team->slug, 'bot' => $bot->id]))
        ->assertRedirect(route('teams.integrations.index', $team));

    $this->assertDatabaseMissing('users', ['id' => $bot->id]);
});

it('mints a scoped token for a bot', function (): void {
    ['team' => $team, 'owner' => $owner] = botManagementFixture();
    $bot = User::factory()->bot($team)->create();

    $this->actingAs($owner)
        ->post(route('teams.integrations.bots.tokens.store', ['team' => $team->slug, 'bot' => $bot->id]), [
            'name' => 'ci-pipeline',
            'abilities' => [IntegrationScope::MessagesWrite->value, IntegrationScope::ChannelsRead->value],
        ])
        ->assertRedirect();

    $token = $bot->tokens()->sole();
    expect($token->name)->toBe('ci-pipeline')
        ->and($token->can(IntegrationScope::MessagesWrite->value))->toBeTrue()
        ->and($token->can(IntegrationScope::MembersWrite->value))->toBeFalse();
});

it('rejects a token with an unknown scope', function (): void {
    ['team' => $team, 'owner' => $owner] = botManagementFixture();
    $bot = User::factory()->bot($team)->create();

    $this->actingAs($owner)
        ->post(route('teams.integrations.bots.tokens.store', ['team' => $team->slug, 'bot' => $bot->id]), [
            'name' => 'bad',
            'abilities' => ['messages:destroy'],
        ])
        ->assertSessionHasErrors('abilities.0');

    expect($bot->tokens()->count())->toBe(0);
});

it('revokes a bot token', function (): void {
    ['team' => $team, 'owner' => $owner] = botManagementFixture();
    $bot = User::factory()->bot($team)->create();
    $token = $bot->createToken('ci', [IntegrationScope::MessagesRead->value]);
    $tokenId = $token->accessToken->getKey();

    $this->actingAs($owner)
        ->delete(route('teams.integrations.bots.tokens.destroy', ['team' => $team->slug, 'bot' => $bot->id, 'token' => $tokenId]))
        ->assertRedirect();

    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
});
