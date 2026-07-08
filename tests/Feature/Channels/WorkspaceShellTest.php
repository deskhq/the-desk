<?php

use App\Enums\ChannelVisibility;
use App\Enums\TeamRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Channel;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Request the workspace (#general) page as an Inertia partial reload that pulls only the
 * lazily-shared `pendingInvitations` prop, mirroring the channel layout's on-mount reload.
 */
function reloadPendingInvitations(User $user)
{
    return test()->actingAs($user)->get(
        route('channels.show', ['team' => $user->currentTeam->slug, 'channel' => Channel::GENERAL_SLUG]),
        [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'channels/Show',
            'X-Inertia-Partial-Data' => 'pendingInvitations',
        ],
    );
}

test('the removed dashboard route returns 404', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/'.$user->currentTeam->slug.'/dashboard')
        ->assertNotFound();
});

test('a logged in user lands in the #general workspace', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('channels.index', ['team' => $user->currentTeam->slug]));

    // Following the workspace entry point lands on #general with the persistent shell's channel list.
    $this->get(route('channels.index', ['team' => $user->currentTeam->slug]))
        ->assertRedirect(route('channels.show', [
            'team' => $user->currentTeam->slug,
            'channel' => Channel::GENERAL_SLUG,
        ]));

    $this->get(route('channels.show', [
        'team' => $user->currentTeam->slug,
        'channel' => Channel::GENERAL_SLUG,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('channels/Show')
            ->where('channel.slug', 'general')
            ->has('channels', 1)
            ->where('channels.0.slug', 'general')
        );
});

test('login to workspace smoke: land in #general, create a channel, then browse', function () {
    $user = User::factory()->create();
    $slug = $user->currentTeam->slug;

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('channels.index', ['team' => $slug]));

    $this->get(route('channels.index', ['team' => $slug]))
        ->assertRedirect(route('channels.show', ['team' => $slug, 'channel' => Channel::GENERAL_SLUG]));

    $this->post(route('channels.store', ['team' => $slug]), [
        'name' => 'Marketing',
        'visibility' => ChannelVisibility::Public->value,
    ])->assertRedirect(route('channels.show', ['team' => $slug, 'channel' => 'marketing']));

    // The new channel is now part of the shared sidebar list.
    $this->get(route('channels.show', ['team' => $slug, 'channel' => 'marketing']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('channels/Show')
            ->has('channels', 2)
        );

    $this->get(route('channels.browse', ['team' => $slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('channels/Browse')
            ->has('joinableChannels')
        );
});

test('pending invitations are not eagerly shared on a full workspace load', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invitedUser)
        ->get(route('channels.show', [
            'team' => $invitedUser->currentTeam->slug,
            'channel' => Channel::GENERAL_SLUG,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->missing('pendingInvitations'));
});

test('pending invitations are shared lazily to the workspace', function () {
    $owner = User::factory()->create(['name' => 'Taylor Otwell']);
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create(['name' => 'Laravel Team']);
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    // A partial reload returns JSON (no rendered view), so assert on the Inertia page payload directly.
    $response = reloadPendingInvitations($invitedUser)->assertOk();

    $response->assertJsonCount(1, 'props.pendingInvitations');
    $response->assertJsonPath('props.pendingInvitations.0.code', $invitation->code);
    $response->assertJsonPath('props.pendingInvitations.0.inviterName', 'Taylor Otwell');
    $response->assertJsonPath('props.pendingInvitations.0.team.name', 'Laravel Team');
    $response->assertJsonPath('props.pendingInvitations.0.team.slug', $team->slug);
    $response->assertJsonMissingPath('props.pendingInvitations.0.teamName');
});

test('the lazily shared invitations exclude accepted, expired, and other users invitations', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    TeamInvitation::factory()->accepted()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $expired = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $other = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'someone@example.com',
        'invited_by' => $owner->id,
    ]);

    reloadPendingInvitations($invitedUser)
        ->assertOk()
        ->assertJsonCount(0, 'props.pendingInvitations');

    // Excluded invitations are filtered, not deleted.
    $this->assertDatabaseHas('team_invitations', ['id' => $expired->id]);
    $this->assertDatabaseHas('team_invitations', ['id' => $other->id]);
});
