<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\IncomingWebhook;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookSubscription;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A team with an owner and an admin, plus a member, for the integrations surface.
 *
 * @return array{team: Team, owner: User, admin: User, member: User}
 */
function integrationsFixture(): array
{
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    return ['team' => $team, 'owner' => $owner, 'admin' => $admin, 'member' => $member];
}

it('renders the integrations home with bots, webhooks, and form options for a manager', function (): void {
    ['team' => $team, 'owner' => $owner] = integrationsFixture();
    $bot = User::factory()->bot($team)->create(['name' => 'Deploy Bot']);
    $channel = Channel::factory()->for($team)->create(['name' => 'ops']);
    IncomingWebhook::factory()->for($team)->for($bot, 'bot')->for($channel, 'channel')->create();
    WebhookSubscription::factory()->for($team)->create();

    $this->actingAs($owner)
        ->get(route('teams.integrations.index', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('teams/integrations/Index')
            ->has('bots', 1)
            ->has('incomingWebhooks', 1)
            ->has('outgoingWebhooks', 1)
            ->has('channels')
            ->has('scopeOptions', 9)
            ->has('eventOptions', 5)
        );
});

it('allows an admin to view the integrations home', function (): void {
    ['team' => $team, 'admin' => $admin] = integrationsFixture();

    $this->actingAs($admin)
        ->get(route('teams.integrations.index', $team))
        ->assertOk();
});

it('forbids a plain member from the integrations home', function (): void {
    ['team' => $team, 'member' => $member] = integrationsFixture();

    $this->actingAs($member)
        ->get(route('teams.integrations.index', $team))
        ->assertForbidden();
});

it('404s the integrations home when the platform is disabled', function (): void {
    config(['integrations.enabled' => false]);
    ['team' => $team, 'owner' => $owner] = integrationsFixture();

    $this->actingAs($owner)
        ->get(route('teams.integrations.index', $team))
        ->assertNotFound();
});

it('redirects a guest away from the integrations home', function (): void {
    ['team' => $team] = integrationsFixture();

    $this->get(route('teams.integrations.index', $team))
        ->assertRedirect(route('login'));
});
