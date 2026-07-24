<?php

use App\Actions\Channels\OpenDirectMessage;
use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function searchTeamWithGeneral(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

/**
 * Add a user to the team and the given channel, returning them.
 */
function searchMember(Team $team, Channel $channel, ?string $name = null): User
{
    $user = User::factory()->create($name ? ['name' => $name] : []);
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);
    $channel->channelMembers()->firstOrCreate(['user_id' => $user->id]);

    return $user;
}

/**
 * Hit the search endpoint for a team as the given user.
 */
function performSearch(User $user, Team $team, string $query): TestResponse
{
    return test()->actingAs($user)->get(route('search', ['team' => $team->slug, 'q' => $query]));
}

/**
 * Hit the search endpoint with an arbitrary set of query parameters (facets).
 *
 * @param  array<string, string>  $params
 */
function performSearchWith(User $user, Team $team, array $params): TestResponse
{
    return test()->actingAs($user)->get(route('search', ['team' => $team->slug, ...$params]));
}

/**
 * Join the user to a channel they are not yet a member of, returning the channel.
 */
function joinChannel(User $user, Channel $channel): Channel
{
    $channel->channelMembers()->firstOrCreate(['user_id' => $user->id]);

    return $channel;
}

/**
 * Hit the quick-switcher JSON suggest endpoint for a team as the given user.
 */
function performSuggest(User $user, Team $team, string $query): TestResponse
{
    return test()->actingAs($user)->getJson(route('search.suggest', ['team' => $team->slug, 'q' => $query]));
}

test('a member searches messages in their channels', function (): void {
    [$owner, $team, $general] = searchTeamWithGeneral();
    $member = searchMember($team, $general, 'Ada Lovelace');
    Message::factory()->for($general)->for($member)->create(['body' => 'the quokka danced at dawn']);
    Message::factory()->for($general)->for($owner)->create(['body' => 'totally unrelated chatter']);

    performSearch($member, $team, 'quokka')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('channels/Search')
            ->where('query', 'quokka')
            ->has('results', 1)
            ->where('results.0.message.body', 'the quokka danced at dawn')
            ->where('results.0.message.user.name', 'Ada Lovelace')
            ->where('results.0.channelName', $general->name)
            ->where('results.0.channelSlug', $general->slug)
            ->where('results.0.isDirectMessage', false)
        );
});

test('a result carries a highlighted snippet of the match', function (): void {
    [$owner, $team, $general] = searchTeamWithGeneral();
    $member = searchMember($team, $general);
    Message::factory()->for($general)->for($owner)->create(['body' => 'the quokka danced at dawn']);

    performSearch($member, $team, 'quokka')
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('results.0.snippet', 'the <mark>quokka</mark> danced at dawn')
        );
});

test('a snippet unwraps mention tokens to plain names', function (): void {
    [$owner, $team, $general] = searchTeamWithGeneral();
    $member = searchMember($team, $general);
    Message::factory()->for($general)->for($owner)->create([
        'body' => 'ping @[Ada Lovelace](3f5b1c2d-1111-2222-3333-444455556666) about quokka',
    ]);

    performSearch($member, $team, 'quokka')
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('results.0.snippet', 'ping @Ada Lovelace about <mark>quokka</mark>')
        );
});

test('the author facet limits results to messages from that user', function (): void {
    [$owner, $team, $general] = searchTeamWithGeneral();
    $ada = searchMember($team, $general, 'Ada');
    $bob = searchMember($team, $general, 'Bob');
    Message::factory()->for($general)->for($ada)->create(['body' => 'zephyr from ada']);
    Message::factory()->for($general)->for($bob)->create(['body' => 'zephyr from bob']);

    performSearchWith($owner, $team, ['q' => 'zephyr', 'from' => $ada->id])
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('results', 1)
            ->where('results.0.message.user.name', 'Ada')
        );
});

test('the channel facet limits results to that channel', function (): void {
    [$owner, $team, $general] = searchTeamWithGeneral();
    $other = joinChannel($owner, Channel::factory()->for($team)->create(['created_by' => $owner->id]));
    Message::factory()->for($general)->for($owner)->create(['body' => 'zephyr in general']);
    Message::factory()->for($other)->for($owner)->create(['body' => 'zephyr in other']);

    performSearchWith($owner, $team, ['q' => 'zephyr', 'in' => $other->id])
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('results', 1)
            ->where('results.0.channelSlug', $other->slug)
        );
});

test('the date facets limit results to the created-at range', function (): void {
    [$owner, $team, $general] = searchTeamWithGeneral();
    $member = searchMember($team, $general);
    Message::factory()->for($general)->for($owner)->create(['body' => 'zephyr old', 'created_at' => now()->subDays(10)]);
    Message::factory()->for($general)->for($owner)->create(['body' => 'zephyr new', 'created_at' => now()->subDay()]);

    performSearchWith($member, $team, ['q' => 'zephyr', 'after' => now()->subDays(2)->toDateString()])
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('results', 1)
            ->where('results.0.message.body', 'zephyr new')
        );

    performSearchWith($member, $team, ['q' => 'zephyr', 'before' => now()->subDays(5)->toDateString()])
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('results', 1)
            ->where('results.0.message.body', 'zephyr old')
        );
});

test('results are ordered newest first regardless of engine relevance order', function (): void {
    [$owner, $team, $general] = searchTeamWithGeneral();
    $member = searchMember($team, $general);
    Message::factory()->for($general)->for($owner)->create(['body' => 'zephyr one', 'created_at' => now()->subDays(3)]);
    Message::factory()->for($general)->for($owner)->create(['body' => 'zephyr two', 'created_at' => now()->subDay()]);

    performSearch($member, $team, 'zephyr')
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('results', 2)
            ->where('results.0.message.body', 'zephyr two')
            ->where('results.1.message.body', 'zephyr one')
        );
});

test('facets never widen the channel ACL', function (): void {
    [$owner, $team, $general] = searchTeamWithGeneral();
    $member = searchMember($team, $general);
    $private = Channel::factory()->for($team)->private()->create(['created_by' => $owner->id]);
    Message::factory()->for($private)->for($owner)->create(['body' => 'secret zephyr plans']);

    // Even naming the owner as author and the private channel as the channel
    // facet, the Eloquent ACL re-assertion keeps the private message hidden.
    performSearchWith($member, $team, ['q' => 'zephyr', 'from' => $owner->id, 'in' => $private->id])
        ->assertInertia(fn (Assert $page): Assert => $page->has('results', 0));
});

test('the date facets must be valid dates', function (): void {
    [, $team, $general] = searchTeamWithGeneral();
    $member = searchMember($team, $general);

    performSearchWith($member, $team, ['q' => 'zephyr', 'after' => 'not-a-date'])
        ->assertSessionHasErrors('after');
});

test('the search page shares the workspace sidebar props', function (): void {
    [, $team, $general] = searchTeamWithGeneral();
    $member = searchMember($team, $general, 'Ada Lovelace');

    // The sidebar feeds off the same shared props as a channel page; the search
    // route lives outside the channels.* name prefix, so it must be covered too.
    performSearch($member, $team, '')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('channels/Search')
            ->has('channels', 1)
            ->where('channels.0.slug', 'general')
            ->has('teamMembers', 2)
        );
});

test('search does not leak messages from channels the user is not a member of', function (): void {
    [$owner, $team, $general] = searchTeamWithGeneral();
    $member = searchMember($team, $general);
    $private = Channel::factory()->for($team)->private()->create(['created_by' => $owner->id]);
    Message::factory()->for($private)->for($owner)->create(['body' => 'secret zephyr plans']);

    performSearch($member, $team, 'zephyr')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->has('results', 0));
});

test('search does not leak messages from other teams the member also belongs to', function (): void {
    [, $team, $general] = searchTeamWithGeneral();
    $member = searchMember($team, $general);

    // A second team the member also belongs to, with a matching message in its
    // own #general — must never surface when searching the first team.
    $otherOwner = User::factory()->create();
    $otherTeam = app(CreateTeam::class)->handle($otherOwner, 'Beta');
    $otherGeneral = Channel::where('team_id', $otherTeam->id)->where('slug', 'general')->firstOrFail();
    $otherTeam->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);
    Message::factory()->for($otherGeneral)->for($otherOwner)->create(['body' => 'crossteam zephyr note']);

    performSearch($member, $team, 'zephyr')
        ->assertInertia(fn (Assert $page): Assert => $page->has('results', 0));
});

test('soft-deleted messages are excluded from search results', function (): void {
    [$owner, $team, $general] = searchTeamWithGeneral();
    $member = searchMember($team, $general);
    $message = Message::factory()->for($general)->for($owner)->create(['body' => 'deletable zephyr note']);
    $message->delete();

    performSearch($member, $team, 'zephyr')
        ->assertInertia(fn (Assert $page): Assert => $page->has('results', 0));
});

test('editing a message changes what search matches', function (): void {
    [$owner, $team, $general] = searchTeamWithGeneral();
    $member = searchMember($team, $general);
    $message = Message::factory()->for($general)->for($owner)->create(['body' => 'original zephyr wording']);

    $message->update(['body' => 'reworded qibble wording']);

    performSearch($member, $team, 'zephyr')
        ->assertInertia(fn (Assert $page): Assert => $page->has('results', 0));
    performSearch($member, $team, 'qibble')
        ->assertInertia(fn (Assert $page): Assert => $page->has('results', 1));
});

test('an empty query renders the page without touching the search engine', function (): void {
    [$owner, $team, $general] = searchTeamWithGeneral();
    $member = searchMember($team, $general);
    Message::factory()->for($general)->for($owner)->create(['body' => 'zephyr']);

    performSearch($member, $team, '')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('channels/Search')
            ->where('query', '')
            ->has('results', 0)
        );
});

test('a team member who belongs to no channel gets no results', function (): void {
    [$owner, $team, $general] = searchTeamWithGeneral();
    $loner = User::factory()->create();
    $team->memberships()->create(['user_id' => $loner->id, 'role' => TeamRole::Member]);
    // The observer joins new members to #general, so drop that membership to
    // model a user who belongs to the team but no channel.
    $loner->channels()->detach($general->id);
    Message::factory()->for($general)->for($owner)->create(['body' => 'zephyr']);

    performSearch($loner, $team, 'zephyr')
        ->assertInertia(fn (Assert $page): Assert => $page->has('results', 0));
});

test('a non-member of the team cannot search it', function (): void {
    [, $team] = searchTeamWithGeneral();
    $outsider = User::factory()->create();

    performSearch($outsider, $team, 'zephyr')->assertForbidden();
});

test('the search query cannot exceed 255 characters', function (): void {
    [, $team, $general] = searchTeamWithGeneral();
    $member = searchMember($team, $general);

    performSearch($member, $team, str_repeat('a', 256))
        ->assertSessionHasErrors('q');
});

test('a jump windows the messages around the target with newer context below', function (): void {
    [$owner, $team, $general] = searchTeamWithGeneral();
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
    [$owner, $team, $general] = searchTeamWithGeneral();
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
    [$owner, $team, $general] = searchTeamWithGeneral();
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
    [$owner, $team, $general] = searchTeamWithGeneral();
    $member = searchMember($team, $general, 'Ada Lovelace');
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
    [$owner, $team, $general] = searchTeamWithGeneral();
    $member = searchMember($team, $general);
    collect(range(1, 8))->each(
        fn (int $i) => Message::factory()->for($general)->for($owner)->create(['body' => "zephyr note {$i}"])
    );

    performSuggest($member, $team, 'zephyr')
        ->assertOk()
        ->assertJsonCount(5, 'results');
});

test('the suggest endpoint is ACL-filtered to the user channels', function (): void {
    [$owner, $team, $general] = searchTeamWithGeneral();
    $member = searchMember($team, $general);
    $private = Channel::factory()->for($team)->private()->create(['created_by' => $owner->id]);
    Message::factory()->for($private)->for($owner)->create(['body' => 'secret zephyr plans']);

    performSuggest($member, $team, 'zephyr')
        ->assertOk()
        ->assertJsonCount(0, 'results');
});

test('an empty suggest query returns no results without touching the engine', function (): void {
    [$owner, $team, $general] = searchTeamWithGeneral();
    $member = searchMember($team, $general);
    Message::factory()->for($general)->for($owner)->create(['body' => 'zephyr']);

    performSuggest($member, $team, '')
        ->assertOk()
        ->assertJsonCount(0, 'results');
});

test('a non-member of the team cannot use suggest', function (): void {
    [, $team] = searchTeamWithGeneral();
    $outsider = User::factory()->create();

    performSuggest($outsider, $team, 'zephyr')->assertForbidden();
});

test('the suggest query cannot exceed 255 characters', function (): void {
    [, $team, $general] = searchTeamWithGeneral();
    $member = searchMember($team, $general);

    performSuggest($member, $team, str_repeat('a', 256))
        ->assertJsonValidationErrorFor('q');
});

test('a search whose results include a direct message renders the counterpart name', function (): void {
    [$owner, $team] = searchTeamWithGeneral();
    $counterpart = User::factory()->create(['name' => 'Grace Hopper']);
    $team->memberships()->create(['user_id' => $counterpart->id, 'role' => TeamRole::Member]);
    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $counterpart);
    Message::factory()->for($dm)->for($counterpart)->create(['body' => 'the quokka hides in a dm']);

    performSearch($owner, $team, 'quokka')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('channels/Search')
            ->has('results', 1)
            ->where('results.0.channelName', 'Grace Hopper')
            ->where('results.0.channelSlug', $dm->slug)
            ->where('results.0.isDirectMessage', true)
        );
});

test('a group direct message result joins the other participants as its name', function (): void {
    [$owner, $team] = searchTeamWithGeneral();
    $ada = User::factory()->create(['name' => 'Ada Lovelace']);
    $grace = User::factory()->create(['name' => 'Grace Hopper']);
    $team->memberships()->create(['user_id' => $ada->id, 'role' => TeamRole::Member]);
    $team->memberships()->create(['user_id' => $grace->id, 'role' => TeamRole::Member]);
    $groupDm = app(OpenDirectMessage::class)->openForUsers($team, $owner, collect([$grace, $ada]));
    Message::factory()->for($groupDm)->for($ada)->create(['body' => 'the quokka joined the group']);

    performSearch($owner, $team, 'quokka')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('results', 1)
            ->where('results.0.channelName', 'Ada Lovelace, Grace Hopper')
        );
});

test('a self direct message result shows the viewer their own name', function (): void {
    [$owner, $team] = searchTeamWithGeneral();
    $selfDm = app(OpenDirectMessage::class)->handle($team, $owner, $owner);
    Message::factory()->for($selfDm)->for($owner)->create(['body' => 'note to self: quokka']);

    performSearch($owner, $team, 'quokka')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('results', 1)
            ->where('results.0.channelName', $owner->name)
        );
});

test('soft-deleted messages report that they should not be searchable', function (): void {
    $message = Message::factory()->create();

    expect($message->shouldBeSearchable())->toBeTrue();

    $message->delete();

    expect($message->shouldBeSearchable())->toBeFalse();
});
