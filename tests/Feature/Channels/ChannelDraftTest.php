<?php

use App\Actions\Channels\PostMessage;
use App\Enums\ChannelVisibility;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * The sidebar `channels` row for the channel, as the acting user.
 *
 * @return array<string, mixed>
 */
function draftSidebarEntry(User $user, Team $team, Channel $channel): array
{
    return sidebarRow($user, $team, $channel)->toArray();
}

/**
 * Save the draft endpoint as the given user.
 */
function saveDraft(User $user, Team $team, Channel $channel, ?string $body): TestResponse
{
    return test()->actingAs($user)->patch(route('channels.draft.update', [
        'team' => $team->slug,
        'channel' => $channel->slug,
    ]), ['body' => $body]);
}

test('a member can save a draft for a channel', function (): void {
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    saveDraft($member, $team, $general, 'a half-written thought')
        ->assertRedirect();

    $this->assertDatabaseHas('channel_members', [
        'channel_id' => $general->id,
        'user_id' => $member->id,
        'draft' => 'a half-written thought',
    ]);
});

test('saving a blank draft clears it', function (): void {
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);
    $member->channels()->updateExistingPivot($general->id, ['draft' => 'stale text']);

    saveDraft($member, $team, $general, '   ')
        ->assertRedirect();

    $this->assertDatabaseHas('channel_members', [
        'channel_id' => $general->id,
        'user_id' => $member->id,
        'draft' => null,
    ]);
})->with([
    'whitespace only' => ['   '],
    'null body' => [null],
]);

test('a non-member cannot save a draft for a channel', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();
    $private = Channel::factory()->for($team)->create([
        'visibility' => ChannelVisibility::Private,
        'created_by' => $owner->id,
    ]);
    $stranger = User::factory()->create();
    $team->memberships()->create(['user_id' => $stranger->id, 'role' => TeamRole::Member]);

    saveDraft($stranger, $team, $private, 'let me in')
        ->assertForbidden();

    $this->assertDatabaseMissing('channel_members', [
        'channel_id' => $private->id,
        'user_id' => $stranger->id,
    ]);
});

test('a draft cannot exceed the message length limit', function (): void {
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    saveDraft($member, $team, $general, str_repeat('a', 8001))
        ->assertSessionHasErrors('body');
});

test('the channel view restores the members saved draft', function (): void {
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);
    $member->channels()->updateExistingPivot($general->id, ['draft' => 'unsent words']);

    $response = $this->actingAs($member)->get(route('channels.show', [
        'team' => $team->slug,
        'channel' => $general->slug,
    ]))->assertOk();

    expect($response->viewData('page')['props']['channel'])
        ->toMatchArray(['draft' => 'unsent words', 'hasDraft' => true]);
});

test('a member without a draft opens the channel with an empty composer', function (): void {
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    $response = $this->actingAs($member)->get(route('channels.show', [
        'team' => $team->slug,
        'channel' => $general->slug,
    ]))->assertOk();

    expect($response->viewData('page')['props']['channel'])
        ->toMatchArray(['draft' => null, 'hasDraft' => false]);
});

test('the sidebar flags a channel with a draft without shipping the draft text', function (): void {
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);
    $member->channels()->updateExistingPivot($general->id, ['draft' => 'secret sauce']);

    expect(draftSidebarEntry($member, $team, $general))
        ->toMatchArray(['hasDraft' => true, 'draft' => null]);
});

test('the sidebar shows no draft cue when the channel has none', function (): void {
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    expect(draftSidebarEntry($member, $team, $general))
        ->toMatchArray(['hasDraft' => false, 'draft' => null]);
});

test('posting a message from the main composer clears the channel draft', function (): void {
    ['team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);
    $member->channels()->updateExistingPivot($general->id, ['draft' => 'about to send this']);

    app(PostMessage::class)->handle(
        channel: $general,
        author: $member,
        body: 'sent it',
        clientUuid: (string) Str::uuid(),
    );

    $this->assertDatabaseHas('channel_members', [
        'channel_id' => $general->id,
        'user_id' => $member->id,
        'draft' => null,
    ]);
});
