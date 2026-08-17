<?php

declare(strict_types=1);

use App\Actions\Channels\OpenDirectMessage;
use App\Models\Message;

/*
|--------------------------------------------------------------------------
| The close-a-direct-message endpoint (#1117)
|--------------------------------------------------------------------------
|
| What closing *does to the sidebar* — the DM leaving the list, a later reply
| re-surfacing it with its badge, reopening un-hiding it, each side closing
| independently — is a claim about `SidebarChannels` and is proven against it in
| `tests/Integration/Support/SidebarChannelsTest.php`. What is left here is the
| HTTP contract: the endpoint stamps the caller's own row, where it redirects
| to, and who is refused.
|
*/

test('closing a direct message stamps the caller\'s own membership and redirects', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $other = teamMemberInChannel($general);
    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);
    Message::factory()->for($dm)->for($owner, 'user')->create();

    $this->actingAs($owner)
        ->post(route('channels.dm.hide', ['team' => $team->slug, 'channel' => $dm->slug]))
        ->assertRedirect();

    expect($dm->channelMembers()->firstWhere('user_id', $owner->id)->hidden_at)->not->toBeNull()
        ->and($dm->channelMembers()->firstWhere('user_id', $other->id)->hidden_at)->toBeNull();
});

test('closing the direct message being viewed redirects home', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $other = teamMemberInChannel($general);
    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);
    Message::factory()->for($dm)->for($owner, 'user')->create();

    $this->actingAs($owner)
        ->post(route('channels.dm.hide', ['team' => $team->slug, 'channel' => $dm->slug]), ['leaving' => true])
        ->assertRedirect(route('channels.index', ['team' => $team->slug]));
});

test('a standard channel cannot be hidden', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $this->actingAs($owner)
        ->post(route('channels.dm.hide', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertForbidden();
});

test('a non-member cannot hide a direct message', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $other = teamMemberInChannel($general);
    $outsider = teamMemberInChannel($general);

    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);

    $this->actingAs($outsider)
        ->post(route('channels.dm.hide', ['team' => $team->slug, 'channel' => $dm->slug]))
        ->assertForbidden();
});
