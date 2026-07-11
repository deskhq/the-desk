<?php

use App\Actions\Channels\OpenDirectMessage;
use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Add a user to the team as a plain member and return them.
 */
function sidebarTeamMember(Team $team): User
{
    $user = User::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);

    return $user;
}

/**
 * The slugs of the channels shipped in the sidebar `channels` prop for the given
 * user viewing #general.
 *
 * @return array<int, string>
 */
function sidebarChannelSlugs(User $viewer, Team $team): array
{
    $slugs = [];

    test()->actingAs($viewer)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => Channel::GENERAL_SLUG]))
        ->assertInertia(function (Assert $page) use (&$slugs) {
            $slugs = collect($page->toArray()['props']['channels'])->pluck('slug')->all();
        });

    return $slugs;
}

test('the creator sees an empty direct message they opened', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $other = sidebarTeamMember($team);

    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);

    expect(sidebarChannelSlugs($owner, $team))->toContain($dm->slug);
});

test('the recipient does not see an empty direct message', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $other = sidebarTeamMember($team);

    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);

    expect(sidebarChannelSlugs($other, $team))->not->toContain($dm->slug);
});

test('the recipient sees a direct message once it has a message', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $other = sidebarTeamMember($team);

    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);
    Message::factory()->for($dm)->for($owner, 'user')->create();

    expect(sidebarChannelSlugs($other, $team))->toContain($dm->slug);
});

test('the recipient sees an empty direct message while actively viewing it', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $other = sidebarTeamMember($team);

    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);

    $slugs = [];
    $this->actingAs($other)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $dm->slug]))
        ->assertInertia(function (Assert $page) use (&$slugs) {
            $slugs = collect($page->toArray()['props']['channels'])->pluck('slug')->all();
        });

    expect($slugs)->toContain($dm->slug);
});

test('a direct message carries its viewer-relative identity in the sidebar prop', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $other = sidebarTeamMember($team);

    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => Channel::GENERAL_SLUG]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('channels', 2)
            ->where('channels', fn ($channels) => collect($channels)->contains(
                fn ($channel) => $channel['slug'] === $dm->slug
                    && $channel['isDirect'] === true
                    && $channel['name'] === $other->name
                    && $channel['dmUserId'] === $other->id
            ))
        );
});

test('a direct message orders on its latest message activity', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $other = sidebarTeamMember($team);

    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);
    $message = Message::factory()->for($dm)->for($owner, 'user')->create();

    $activity = null;
    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => Channel::GENERAL_SLUG]))
        ->assertInertia(function (Assert $page) use (&$activity, $dm) {
            $activity = collect($page->toArray()['props']['channels'])
                ->firstWhere('slug', $dm->slug)['lastActivityAt'];
        });

    expect($activity)->not->toBeNull()
        ->and(Carbon::parse($activity)->timestamp)
        ->toBe($message->created_at->timestamp);
});
