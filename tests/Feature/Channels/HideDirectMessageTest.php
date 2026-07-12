<?php

use App\Actions\Channels\OpenDirectMessage;
use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Add a user to the team as a plain member and return them.
 */
function hideTeamMember(Team $team): User
{
    $user = User::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);

    return $user;
}

/**
 * The channels shipped in the sidebar `channels` prop for the given user viewing
 * #general, keyed by slug.
 *
 * @return array<string, array<string, mixed>>
 */
function hideSidebarChannels(User $viewer, Team $team): array
{
    $channels = [];

    test()->actingAs($viewer)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => Channel::GENERAL_SLUG]))
        ->assertInertia(function (Assert $page) use (&$channels): void {
            $channels = collect($page->toArray()['props']['channels'])->keyBy('slug')->all();
        });

    return $channels;
}

test('hiding a direct message removes it from the sidebar', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $other = hideTeamMember($team);

    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);
    Message::factory()->for($dm)->for($owner, 'user')->create();

    expect(hideSidebarChannels($owner, $team))->toHaveKey($dm->slug);

    $this->actingAs($owner)
        ->post(route('channels.dm.hide', ['team' => $team->slug, 'channel' => $dm->slug]))
        ->assertRedirect();

    expect(hideSidebarChannels($owner, $team))->not->toHaveKey($dm->slug);
});

test('hiding an empty direct message the creator opened removes it from the sidebar', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $other = hideTeamMember($team);

    // An empty DM the owner opened lists via the creator override, with no
    // messages, so hiding it exercises the "no message since" predicate branch.
    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);

    expect(hideSidebarChannels($owner, $team))->toHaveKey($dm->slug);

    $this->actingAs($owner)
        ->post(route('channels.dm.hide', ['team' => $team->slug, 'channel' => $dm->slug]));

    expect(hideSidebarChannels($owner, $team))->not->toHaveKey($dm->slug);
});

test('a message after hiding re-surfaces the direct message with an unread badge', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $other = hideTeamMember($team);

    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);
    Message::factory()->for($dm)->for($owner, 'user')->create();

    $this->actingAs($owner)
        ->post(route('channels.dm.hide', ['team' => $team->slug, 'channel' => $dm->slug]));

    expect(hideSidebarChannels($owner, $team))->not->toHaveKey($dm->slug);

    // A later reply from the other participant arrives after the hide instant.
    Message::factory()->for($dm)->for($other, 'user')->create(['created_at' => now()->addMinutes(5)]);

    $channels = hideSidebarChannels($owner, $team);
    expect($channels)->toHaveKey($dm->slug)
        ->and($channels[$dm->slug]['unreadCount'])->toBe(1);
});

test('reopening a hidden direct message un-hides it', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $other = hideTeamMember($team);

    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);
    Message::factory()->for($dm)->for($owner, 'user')->create();

    $this->actingAs($owner)
        ->post(route('channels.dm.hide', ['team' => $team->slug, 'channel' => $dm->slug]));

    expect(hideSidebarChannels($owner, $team))->not->toHaveKey($dm->slug);

    // Opening the same DM again (people picker / quick switcher) resurfaces it.
    app(OpenDirectMessage::class)->handle($team, $owner, $other);

    expect(hideSidebarChannels($owner, $team))->toHaveKey($dm->slug);
});

test('hiding is per member and does not hide the direct message for the other participant', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $other = hideTeamMember($team);

    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);
    Message::factory()->for($dm)->for($owner, 'user')->create();

    $this->actingAs($owner)
        ->post(route('channels.dm.hide', ['team' => $team->slug, 'channel' => $dm->slug]));

    expect(hideSidebarChannels($owner, $team))->not->toHaveKey($dm->slug);
    expect(hideSidebarChannels($other, $team))->toHaveKey($dm->slug);
});

test('closing the direct message being viewed redirects home', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $other = hideTeamMember($team);

    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);
    Message::factory()->for($dm)->for($owner, 'user')->create();

    $this->actingAs($owner)
        ->post(route('channels.dm.hide', ['team' => $team->slug, 'channel' => $dm->slug]), ['leaving' => true])
        ->assertRedirect(route('channels.index', ['team' => $team->slug]));

    expect(hideSidebarChannels($owner, $team))->not->toHaveKey($dm->slug);
});

test('a standard channel cannot be hidden', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = $team->channels()->where('slug', Channel::GENERAL_SLUG)->firstOrFail();

    $this->actingAs($owner)
        ->post(route('channels.dm.hide', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertForbidden();
});

test('a non-member cannot hide a direct message', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $other = hideTeamMember($team);
    $outsider = hideTeamMember($team);

    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);

    $this->actingAs($outsider)
        ->post(route('channels.dm.hide', ['team' => $team->slug, 'channel' => $dm->slug]))
        ->assertForbidden();
});
