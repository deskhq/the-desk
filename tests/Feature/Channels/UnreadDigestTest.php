<?php

declare(strict_types=1);

use App\Enums\NotificationLevel;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Database\Factories\ChannelMemberFactory;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The one genuinely volatile fact a navigation ships: what the viewer has not
 * read, per channel and per workspace, plus whether any followed thread is
 * waiting.
 *
 * Asserted through a real Inertia visit rather than against `share()` or the
 * read models under it, because the digest exists to be a *prop* — its whole
 * point is which shape reaches the client, and a unit test on the derivation
 * would keep passing the day the prop stopped being shared at all.
 */

/**
 * Post a message in the channel that names the given user.
 */
function mentionPost(Channel $channel, User $author, User $mentioned): Message
{
    $message = Message::factory()->for($channel)->for($author)->create([
        'body' => "hey @[{$mentioned->name}]({$mentioned->id})",
    ]);

    $message->mentionedUsers()->attach($mentioned->id);

    return $message;
}

/**
 * Visit a channel as the given user and hand back the Inertia assertion.
 */
function visitChannel(User $viewer, Channel $channel): Assert
{
    $page = null;

    test()->actingAs($viewer)
        ->get(route('channels.show', ['team' => $channel->team->slug, 'channel' => $channel->slug]))
        ->assertOk()
        ->assertInertia(function (Assert $inertia) use (&$page): void {
            $page = $inertia;
        });

    /** @var Assert $page */
    return $page;
}

/**
 * The partial reload the client makes after marking a channel read, asking for
 * the named props and nothing else.
 *
 * A partial reload answers with JSON rather than a rendered view, so it is
 * asserted on the page payload directly — {@see Assert} only reads a view.
 */
function reloadOnly(User $viewer, Channel $channel, string $only): TestResponse
{
    return test()->actingAs($viewer)
        ->get(
            route('channels.show', ['team' => $channel->team->slug, 'channel' => $channel->slug]),
            [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
                'X-Inertia-Partial-Component' => 'channels/Show',
                'X-Inertia-Partial-Data' => $only,
            ],
        )
        ->assertOk();
}

test('a visit carries the unread digest and the rosters carry no badge fields', function (): void {
    ['owner' => $owner, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    Message::factory()->for($general)->for($owner)->create();

    visitChannel($member, $general)
        ->where("unread.channels.{$general->id}.unread", 1)
        ->where("unread.channels.{$general->id}.mention", 0)
        ->missing('channels.0.unreadCount')
        ->missing('channels.0.mentionCount')
        ->missing('teams.0.unreadCount')
        ->missing('teams.0.mentionCount')
        ->missing('currentTeam.unreadCount')
        ->missing('hasUnreadThreads');
});

test('marking a channel read clears its badge, and the trip carries the digest without the roster', function (): void {
    ['owner' => $owner, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    Message::factory()->for($general)->for($owner)->create();

    visitChannel($member, $general)->where("unread.channels.{$general->id}.unread", 1);

    $this->actingAs($member)
        ->post(route('channels.read', ['team' => $general->team->slug, 'channel' => $general->slug]))
        ->assertRedirect();

    // The one prop the client asks back for. The badge is gone from the digest,
    // and the channel roster — 7.8 KB to move four integers before this — never
    // enters the response at all.
    reloadOnly($member, $general, 'unread')
        ->assertJsonPath('props.unread.threads', false)
        ->assertJsonMissingPath("props.unread.channels.{$general->id}")
        ->assertJsonMissingPath('props.channels')
        ->assertJsonMissingPath('props.teamMembers');
});

test('suppression stays server-side: a muted channel badges nothing, a mentions-only channel only mentions', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    $muted = Channel::factory()->for($team)->create(['name' => 'Muted', 'slug' => 'muted']);
    channelMembership($muted, $member, fn (ChannelMemberFactory $membership): ChannelMemberFactory => $membership->muted());
    $muted->members()->attach($owner);

    $mentionsOnly = Channel::factory()->for($team)->create(['name' => 'Quiet', 'slug' => 'quiet']);
    channelMembership(
        $mentionsOnly,
        $member,
        fn (ChannelMemberFactory $membership): ChannelMemberFactory => $membership->notificationLevel(NotificationLevel::Mentions),
    );
    $mentionsOnly->members()->attach($owner);

    foreach ([$muted, $mentionsOnly] as $channel) {
        Message::factory()->for($channel)->for($owner)->create();
        mentionPost($channel, $owner, $member);
    }

    visitChannel($member, $general)
        // Muted silences both readings, so the channel is absent from the digest
        // entirely rather than present with two zeroes.
        ->missing("unread.channels.{$muted->id}")
        // "Mentions only" keeps the one message that named the viewer and
        // silences the ordinary traffic beside it.
        ->where("unread.channels.{$mentionsOnly->id}.unread", 0)
        ->where("unread.channels.{$mentionsOnly->id}.mention", 1);
});

test('the workspace reading is the channel readings summed', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    $second = Channel::factory()->for($team)->create(['name' => 'Second', 'slug' => 'second']);
    channelMembership($second, $member);
    $second->members()->attach($owner);

    Message::factory()->count(2)->for($general)->for($owner)->create();
    mentionPost($general, $owner, $member);
    Message::factory()->for($second)->for($owner)->create();

    // The muted channel contributes to neither reading, which is the drift the
    // single query exists to prevent: a workspace dot lit by traffic no row
    // inside it is allowed to show would be worse than no dot at all.
    $muted = Channel::factory()->for($team)->create(['name' => 'Muted', 'slug' => 'muted']);
    channelMembership($muted, $member, fn (ChannelMemberFactory $membership): ChannelMemberFactory => $membership->muted());
    $muted->members()->attach($owner);
    Message::factory()->count(5)->for($muted)->for($owner)->create();

    visitChannel($member, $general)
        ->where("unread.channels.{$general->id}", ['unread' => 3, 'mention' => 1])
        ->where("unread.channels.{$second->id}", ['unread' => 1, 'mention' => 0])
        ->where("unread.teams.{$team->id}", ['unread' => 4, 'mention' => 1]);
});
