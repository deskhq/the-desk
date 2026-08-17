<?php

declare(strict_types=1);

use App\Actions\Channels\OpenDirectMessage;
use App\Models\Channel;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The sidebar `channels` prop, as the client receives it (#1117)
|--------------------------------------------------------------------------
|
| Which direct messages the sidebar lists — the creator override, the empty-DM
| rule, close and re-surface, activity ordering — is a claim about
| `SidebarChannels` and is proven against it directly in
| `tests/Integration/Support/SidebarChannelsTest.php`. What is left here is the
| part only HTTP can show: that `channels/Show` ships the prop, and that the
| viewer the request resolves is the one the row is named for — the request's
| user reaches `ChannelData::fromChannel()` as an explicit argument (#1113), and
| a controller that handed it the wrong one would still render a plausible page.
|
*/

test('the rendered sidebar prop carries a direct message with its viewer-relative identity', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $other = teamMemberInChannel($general);
    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => Channel::GENERAL_SLUG]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('channels', 2)
            ->where('channels', fn ($channels) => collect($channels)->contains(
                fn ($channel): bool => $channel['slug'] === $dm->slug
                    && $channel['isDirect'] === true
                    && $channel['name'] === $other->name
                    && $channel['dmUserId'] === $other->id
            ))
        );
});
