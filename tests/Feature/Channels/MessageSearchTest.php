<?php

use App\Enums\NavDestination;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Open the Search destination on the team's #general as the given user.
 *
 * Search is a dock panel, so its props ride on an ordinary workspace route with
 * `?nav=search` pinned; #general stands in for "whatever channel the viewer had
 * open", which is where a real search always runs from.
 */
function performSearch(User $user, Team $team, string $query): TestResponse
{
    return performSearchWith($user, $team, ['q' => $query]);
}

/**
 * Open the Search destination with an arbitrary set of query parameters (facets).
 *
 * @param  array<string, string>  $params
 */
function performSearchWith(User $user, Team $team, array $params): TestResponse
{
    return test()->actingAs($user)->get(route('channels.show', [
        'team' => $team->slug,
        'channel' => Channel::GENERAL_SLUG,
        ...$params,
        // Last, so a facet a caller passes can never steer the destination.
        'nav' => NavDestination::Search->value,
    ]));
}

/**
 * Hit the quick-switcher JSON suggest endpoint for a team as the given user.
 */
function performSuggest(User $user, Team $team, string $query): TestResponse
{
    return test()->actingAs($user)->getJson(route('search.suggest', ['team' => $team->slug, 'q' => $query]));
}

test('a member searches messages in their channels', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general, ['name' => 'Ada Lovelace']);
    Message::factory()->for($general)->for($member)->create(['body' => 'the quokka danced at dawn']);
    Message::factory()->for($general)->for($owner)->create(['body' => 'totally unrelated chatter']);

    performSearch($member, $team, 'quokka')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('channels/Show')
            ->has('searchResults', 1)
            ->where('searchResults.0.message.body', 'the quokka danced at dawn')
            ->where('searchResults.0.message.user.name', 'Ada Lovelace')
            ->where('searchResults.0.channelName', $general->name)
            ->where('searchResults.0.channelSlug', $general->slug)
            ->where('searchResults.0.isDirectMessage', false)
        );
});

test('the panel props are absent until the destination is pinned', function (): void {
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    // A search runs a full-text query, so it must ride on `?nav=search` rather
    // than on every workspace navigation.
    test()->actingAs($member)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->missing('searchResults')
        );
});

test('the legacy search url redirects onto the destination carrying its facets', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    // #391's shared links have to keep working: the old page is a redirect that
    // hands its query and facets to the panel.
    test()->actingAs($member)
        ->get(route('search', [
            'team' => $team->slug,
            'q' => 'zephyr',
            'from' => $owner->id,
            'has' => 'file',
            'nav' => 'threads',
        ]))
        ->assertRedirectContains('q=zephyr')
        ->assertRedirectContains('from='.$owner->id)
        ->assertRedirectContains('has=file')
        // The destination is pinned last, so a crafted `nav` on the legacy link
        // cannot steer the redirect at another panel.
        ->assertRedirectContains('nav=search');
});

test('the search destination shares the workspace sidebar props', function (): void {
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general, ['name' => 'Ada Lovelace']);

    // The facet pickers read the channels and members off the same shared props
    // the conversation list feeds on.
    performSearch($member, $team, '')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('channels/Show')
            ->has('channels', 1)
            ->where('channels.0.slug', 'general')
            ->has('teamMembers', 2)
        );
});

test('a non-member of the team cannot search it', function (): void {
    ['team' => $team] = teamWithChannel();
    $outsider = User::factory()->create();

    performSearch($outsider, $team, 'zephyr')->assertForbidden();
});

test('a jump windows the messages around the target with newer context below', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $messages = collect(range(1, 30))->map(
        fn (int $i) => Message::factory()->for($general)->for($owner)->create(['body' => "message {$i}"])
    );
    $target = $messages[4]; // message 5

    $this->actingAs($owner)->get(route('channels.show', [
        'team' => $team->slug,
        'channel' => $general->slug,
        'message' => $target->id,
    ]))->assertInertia(fn (Assert $page): Assert => $page
        ->where('jumpToMessageId', $target->id)
        // 15 messages newer than the target cap the window (message 20), so the
        // window is messages 1..20 newest-first and messages 21..30 are excluded.
        ->has('messages.data', 20)
        ->where('messages.data.0.body', 'message 20')
    );
});

test('a jump to the newest message keeps it at the bottom of the window', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $messages = collect(range(1, 5))->map(
        fn (int $i) => Message::factory()->for($general)->for($owner)->create(['body' => "message {$i}"])
    );
    $target = $messages[4]; // message 5, the newest

    $this->actingAs($owner)->get(route('channels.show', [
        'team' => $team->slug,
        'channel' => $general->slug,
        'message' => $target->id,
    ]))->assertInertia(fn (Assert $page): Assert => $page
        ->where('jumpToMessageId', $target->id)
        ->has('messages.data', 5)
        ->where('messages.data.0.body', 'message 5')
    );
});

test('a message param from another channel is ignored', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    Message::factory()->for($general)->for($owner)->create(['body' => 'only message here']);
    $other = Channel::factory()->for($team)->create(['created_by' => $owner->id]);
    $foreign = Message::factory()->for($other)->for($owner)->create(['body' => 'elsewhere']);

    $this->actingAs($owner)->get(route('channels.show', [
        'team' => $team->slug,
        'channel' => $general->slug,
        'message' => $foreign->id,
    ]))->assertInertia(fn (Assert $page): Assert => $page
        ->where('jumpToMessageId', null)
        ->has('messages.data', 1)
    );
});

test('the suggest endpoint returns matching messages as JSON', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general, ['name' => 'Ada Lovelace']);
    Message::factory()->for($general)->for($member)->create(['body' => 'the quokka danced at dawn']);
    Message::factory()->for($general)->for($owner)->create(['body' => 'totally unrelated chatter']);

    performSuggest($member, $team, 'quokka')
        ->assertOk()
        ->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.message.body', 'the quokka danced at dawn')
        ->assertJsonPath('results.0.message.user.name', 'Ada Lovelace')
        ->assertJsonPath('results.0.channelName', $general->name)
        ->assertJsonPath('results.0.channelSlug', $general->slug);
});

test('the suggest endpoint caps results at the preview limit', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);
    collect(range(1, 8))->each(
        fn (int $i) => Message::factory()->for($general)->for($owner)->create(['body' => "zephyr note {$i}"])
    );

    performSuggest($member, $team, 'zephyr')
        ->assertOk()
        ->assertJsonCount(5, 'results');
});

test('the suggest endpoint honours the file facet', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);
    $withFile = Message::factory()->for($general)->for($owner)->create(['body' => 'zephyr with the deck']);
    Message::factory()->for($general)->for($owner)->create(['body' => 'zephyr without anything']);
    Attachment::factory()->for($owner)->attachedTo($withFile)->create();

    // The palette parses `has:file` into the same params the panel writes, so the
    // preview it shows has to be filtered the same way the full result set is.
    test()->actingAs($member)
        ->getJson(route('search.suggest', ['team' => $team->slug, 'q' => 'zephyr', 'has' => 'file']))
        ->assertOk()
        ->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.message.body', 'zephyr with the deck');
});

test('the suggest endpoint rejects a file facet it does not offer', function (): void {
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    test()->actingAs($member)
        ->getJson(route('search.suggest', ['team' => $team->slug, 'q' => 'zephyr', 'has' => 'audio']))
        ->assertJsonValidationErrorFor('has');
});

test('the suggest endpoint is ACL-filtered to the user channels', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);
    $private = Channel::factory()->for($team)->private()->create(['created_by' => $owner->id]);
    Message::factory()->for($private)->for($owner)->create(['body' => 'secret zephyr plans']);

    performSuggest($member, $team, 'zephyr')
        ->assertOk()
        ->assertJsonCount(0, 'results');
});

test('an empty suggest query returns no results without touching the engine', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);
    Message::factory()->for($general)->for($owner)->create(['body' => 'zephyr']);

    performSuggest($member, $team, '')
        ->assertOk()
        ->assertJsonCount(0, 'results');
});

test('a non-member of the team cannot use suggest', function (): void {
    ['team' => $team] = teamWithChannel();
    $outsider = User::factory()->create();

    performSuggest($outsider, $team, 'zephyr')->assertForbidden();
});

test('the suggest query cannot exceed 255 characters', function (): void {
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    performSuggest($member, $team, str_repeat('a', 256))
        ->assertJsonValidationErrorFor('q');
});

test('soft-deleted messages report that they should not be searchable', function (): void {
    $message = Message::factory()->create();

    expect($message->shouldBeSearchable())->toBeTrue();

    $message->delete();

    expect($message->shouldBeSearchable())->toBeFalse();
});
