<?php

declare(strict_types=1);

use App\Actions\Channels\OpenDirectMessage;
use App\Actions\Teams\CreateTeam;
use App\Data\MessageSearchResultData;
use App\Enums\TeamRole;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Support\MessageSearchPanel;
use Illuminate\Http\Request;

/**
 * The Search destination's read-model, exercised the way the shell builds it —
 * a viewer, a team, and the facets pinned on the URL — with no HTTP round-trip.
 * The panel is a constructible object, so what it selects and how it shapes a
 * match are answerable without routing a request through the whole workspace.
 *
 * The wiring that *is* HTTP (the destination riding `?nav=search`, the legacy
 * redirect, the team-membership gate, the JSON suggest endpoint) stays in
 * tests/Feature/Channels/MessageSearchTest.php.
 */

/**
 * The panel the shell would build for these facets on the URL.
 *
 * @param  array<string, mixed>  $params
 */
function panelFor(User $viewer, Team $team, array $params): MessageSearchPanel
{
    $request = Request::create('/'.$team->slug.'/general', parameters: $params);

    return new MessageSearchPanel($viewer, $team, MessageSearchPanel::criteriaFromRequest($request));
}

/**
 * The bodies the panel matches, in the order it returns them.
 *
 * @param  array<int, MessageSearchResultData>  $results
 * @return array<int, string>
 */
function panelBodies(array $results): array
{
    return array_map(fn (MessageSearchResultData $result): string => $result->message->body, $results);
}

test('a member matches messages in their channels, shaped for the client', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general, ['name' => 'Ada Lovelace']);
    Message::factory()->for($general)->for($member)->create(['body' => 'the quokka danced at dawn']);
    Message::factory()->for($general)->for($owner)->create(['body' => 'totally unrelated chatter']);

    $results = panelFor($member, $team, ['q' => 'quokka'])->results();

    expect($results)->toHaveCount(1)
        ->and($results[0])
        ->message->body->toBe('the quokka danced at dawn')
        ->message->user->name->toBe('Ada Lovelace')
        ->channelName->toBe($general->name)
        ->channelSlug->toBe($general->slug)
        ->isDirectMessage->toBeFalse()
        ->teamSlug->toBe($team->slug);
});

test('a result carries a highlighted snippet, with mention tokens unwrapped', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);
    Message::factory()->for($general)->for($owner)->create(['body' => 'the quokka danced at dawn']);

    expect(panelFor($member, $team, ['q' => 'quokka'])->results()[0]->snippet)
        ->toBe('the <mark>quokka</mark> danced at dawn');

    Message::factory()->for($general)->for($owner)->create([
        'body' => 'ping @[Ada Lovelace](3f5b1c2d-1111-2222-3333-444455556666) about qibble',
    ]);

    expect(panelFor($member, $team, ['q' => 'qibble'])->results()[0]->snippet)
        ->toBe('ping @Ada Lovelace about <mark>qibble</mark>');
});

test('the author facet limits matches to messages from that user', function (): void {
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    $ada = teamMemberInChannel($general, ['name' => 'Ada']);
    $bob = teamMemberInChannel($general, ['name' => 'Bob']);
    Message::factory()->for($general)->for($ada)->create(['body' => 'zephyr from ada']);
    Message::factory()->for($general)->for($bob)->create(['body' => 'zephyr from bob']);

    expect(panelBodies(panelFor($ada, $team, ['q' => 'zephyr', 'from' => $ada->id])->results()))
        ->toBe(['zephyr from ada']);
});

test('the channel facet limits matches to that channel', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $other = Channel::factory()->for($team)->create(['created_by' => $owner->id]);
    $other->channelMembers()->firstOrCreate(['user_id' => $owner->id]);
    Message::factory()->for($general)->for($owner)->create(['body' => 'zephyr in general']);
    Message::factory()->for($other)->for($owner)->create(['body' => 'zephyr in other']);

    expect(panelBodies(panelFor($owner, $team, ['q' => 'zephyr', 'in' => $other->id])->results()))
        ->toBe(['zephyr in other']);
});

test('the date facets limit matches to the created-at range', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);
    Message::factory()->for($general)->for($owner)->create(['body' => 'zephyr old', 'created_at' => now()->subDays(10)]);
    Message::factory()->for($general)->for($owner)->create(['body' => 'zephyr new', 'created_at' => now()->subDay()]);

    expect(panelBodies(panelFor($member, $team, ['q' => 'zephyr', 'after' => now()->subDays(2)->toDateString()])->results()))
        ->toBe(['zephyr new'])
        ->and(panelBodies(panelFor($member, $team, ['q' => 'zephyr', 'before' => now()->subDays(5)->toDateString()])->results()))
        ->toBe(['zephyr old']);
});

test('the file facet limits matches to messages still carrying an attachment', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);
    $withFile = Message::factory()->for($general)->for($owner)->create(['body' => 'zephyr with the deck']);
    Message::factory()->for($general)->for($owner)->create(['body' => 'zephyr without anything']);
    $attachment = Attachment::factory()->for($owner)->attachedTo($withFile)->create();

    expect(panelBodies(panelFor($member, $team, ['q' => 'zephyr', 'has' => 'file'])->results()))
        ->toBe(['zephyr with the deck']);

    // The chip promises the message still carries a file, and a soft-deleted one
    // is no longer rendered on it — so it must not answer the filter either.
    $attachment->delete();

    expect(panelFor($member, $team, ['q' => 'zephyr', 'has' => 'file'])->results())->toBe([]);
});

test('matches come back newest first regardless of engine relevance order', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);
    Message::factory()->for($general)->for($owner)->create(['body' => 'zephyr one', 'created_at' => now()->subDays(3)]);
    Message::factory()->for($general)->for($owner)->create(['body' => 'zephyr two', 'created_at' => now()->subDay()]);

    expect(panelBodies(panelFor($member, $team, ['q' => 'zephyr'])->results()))
        ->toBe(['zephyr two', 'zephyr one']);
});

test('no facet widens the channel ACL', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);
    $private = Channel::factory()->for($team)->private()->create(['created_by' => $owner->id]);
    $secret = Message::factory()->for($private)->for($owner)->create(['body' => 'secret zephyr deck']);
    Attachment::factory()->for($owner)->attachedTo($secret)->create();

    // Even naming the owner as author and the private channel as the channel
    // facet, the Eloquent ACL re-assertion keeps the private message hidden.
    expect(panelFor($member, $team, ['q' => 'zephyr', 'from' => $owner->id, 'in' => $private->id])->results())->toBe([])
        ->and(panelFor($member, $team, ['q' => 'zephyr', 'has' => 'file'])->results())->toBe([]);
});

test('matches never cross into another team the viewer also belongs to', function (): void {
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    $otherOwner = User::factory()->create();
    $otherTeam = app(CreateTeam::class)->handle($otherOwner, 'Beta');
    $otherGeneral = Channel::where('team_id', $otherTeam->id)->where('slug', Channel::GENERAL_SLUG)->firstOrFail();
    $otherTeam->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);
    Message::factory()->for($otherGeneral)->for($otherOwner)->create(['body' => 'crossteam zephyr note']);

    expect(panelFor($member, $team, ['q' => 'zephyr'])->results())->toBe([]);
});

test('a soft-deleted message drops out, and an edit changes what matches', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    $deleted = Message::factory()->for($general)->for($owner)->create(['body' => 'deletable zephyr note']);
    $deleted->delete();

    $edited = Message::factory()->for($general)->for($owner)->create(['body' => 'original zephyr wording']);
    $edited->update(['body' => 'reworded qibble wording']);

    expect(panelFor($member, $team, ['q' => 'zephyr'])->results())->toBe([])
        ->and(panelBodies(panelFor($member, $team, ['q' => 'qibble'])->results()))->toBe(['reworded qibble wording']);
});

test('a team member who belongs to no channel matches nothing', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $loner = User::factory()->create();
    $team->memberships()->create(['user_id' => $loner->id, 'role' => TeamRole::Member]);
    // The observer joins new members to #general, so drop that membership to
    // model a user who belongs to the team but no channel.
    $loner->channels()->detach($general->id);
    Message::factory()->for($general)->for($owner)->create(['body' => 'zephyr']);

    expect(panelFor($loner, $team, ['q' => 'zephyr'])->results())->toBe([]);
});

test('an empty query matches nothing without touching the search engine', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);
    Message::factory()->for($general)->for($owner)->create(['body' => 'zephyr']);

    expect(panelFor($member, $team, ['q' => ''])->results())->toBe([])
        ->and(panelFor($member, $team, [])->results())->toBe([]);
});

test('a query longer than the engine accepts runs no search at all', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);
    Message::factory()->for($general)->for($owner)->create(['body' => str_repeat('a', 300)]);

    // Dropped, not truncated: half a query is a different search, and the panel
    // shows its empty state rather than results for something nobody asked for.
    expect(panelFor($member, $team, ['q' => str_repeat('a', 256)])->results())->toBe([]);
});

test('a malformed facet is dropped rather than rejecting the shell', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);
    Message::factory()->for($general)->for($owner)->create(['body' => 'zephyr note']);

    // The panel's criteria are resolved in the shared props of whatever workspace
    // route the dock sits on, so a hand-edited facet cannot be allowed to 422 the
    // whole workspace: it is dropped, and the search runs without it.
    $malformed = [
        // An unparseable date names no bound.
        ['q' => 'zephyr', 'after' => 'not-a-date'],
        // Only the calendar-day format the facets write is parsed, so "yesterday"
        // never becomes an upper bound that would hide today's match.
        ['q' => 'zephyr', 'before' => 'yesterday'],
        // Only the one value the chip writes is honoured; a hand-edited
        // `has=audio` filters by nothing rather than by something nobody asked for.
        ['q' => 'zephyr', 'has' => 'audio'],
        // The facets never send an array, so one on the URL names no author.
        ['q' => 'zephyr', 'from' => ['nobody']],
    ];

    foreach ($malformed as $params) {
        expect(panelBodies(panelFor($member, $team, $params)->results()))->toBe(['zephyr note']);
    }
});

test('the criteria are echoed back as the client writes them onto the URL', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    expect(panelFor($member, $team, ['q' => 'zephyr', 'has' => 'file', 'from' => $owner->id, 'after' => '2026-01-02'])->criteria())
        ->toMatchArray([
            'q' => 'zephyr',
            'has' => 'file',
            'from' => $owner->id,
            'after' => '2026-01-02',
            'before' => null,
            'in' => null,
        ]);

    // A dropped facet is echoed as absent, so the client can tell that the
    // matches it holds do not answer the chip it drew.
    expect(panelFor($member, $team, ['q' => 'zephyr', 'has' => 'audio'])->criteria()['has'])->toBeNull();
});

test('a direct message match is named after the viewer\'s counterpart', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();
    $counterpart = User::factory()->create(['name' => 'Grace Hopper']);
    $team->memberships()->create(['user_id' => $counterpart->id, 'role' => TeamRole::Member]);
    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $counterpart);
    Message::factory()->for($dm)->for($counterpart)->create(['body' => 'the quokka hides in a dm']);

    $results = panelFor($owner, $team, ['q' => 'quokka'])->results();

    expect($results)->toHaveCount(1)
        ->and($results[0])
        ->channelName->toBe('Grace Hopper')
        ->channelSlug->toBe($dm->slug)
        ->isDirectMessage->toBeTrue();
});

test('a group direct message match joins the other participants as its name', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();
    $ada = User::factory()->create(['name' => 'Ada Lovelace']);
    $grace = User::factory()->create(['name' => 'Grace Hopper']);
    $team->memberships()->create(['user_id' => $ada->id, 'role' => TeamRole::Member]);
    $team->memberships()->create(['user_id' => $grace->id, 'role' => TeamRole::Member]);
    $groupDm = app(OpenDirectMessage::class)->openForUsers($team, $owner, collect([$grace, $ada]));
    Message::factory()->for($groupDm)->for($ada)->create(['body' => 'the quokka joined the group']);

    expect(panelFor($owner, $team, ['q' => 'quokka'])->results()[0])
        ->channelName->toBe('Ada Lovelace, Grace Hopper')
        ->isDirectMessage->toBeTrue();
});

test('a self direct message match shows the viewer their own name', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();
    $selfDm = app(OpenDirectMessage::class)->handle($team, $owner, $owner);
    Message::factory()->for($selfDm)->for($owner)->create(['body' => 'note to self: quokka']);

    expect(panelFor($owner, $team, ['q' => 'quokka'])->results()[0])
        ->channelName->toBe($owner->name)
        ->isDirectMessage->toBeTrue();
});

test('the channel facet union spans every workspace the viewer belongs to', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $otherTeam = app(CreateTeam::class)->handle($owner, 'Beta');
    $otherGeneral = Channel::where('team_id', $otherTeam->id)->where('slug', Channel::GENERAL_SLUG)->firstOrFail();

    // "All workspaces" mode offers channels the current team's own list cannot,
    // each tagged with the workspace that disambiguates a same-named channel.
    $union = panelFor($owner, $team, ['q' => 'zephyr'])->workspaceChannels();

    expect(array_column($union, 'id'))->toContain($general->id, $otherGeneral->id)
        ->and(array_column($union, 'teamSlug'))->toContain($team->slug, $otherTeam->slug);
});
