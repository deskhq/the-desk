<?php

declare(strict_types=1);

use App\Actions\Channels\DeleteChannel;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;

/**
 * Seed a workspace with a second, deletable channel the owner belongs to —
 * #general is never deletable, so the delete affordance needs a channel of its
 * own — carrying a message so the destruction summary counts something.
 *
 * @return array{owner: User, team: Team, channel: Channel}
 */
function browserTeamWithDeletableChannel(): array
{
    ['owner' => $alice, 'team' => $team] = browserTeamWithChannel();

    $channel = Channel::factory()->for($team)->create(['name' => 'Roadmap', 'slug' => 'roadmap']);
    $channel->channelMembers()->create(['user_id' => $alice->id]);
    Message::factory()->for($channel)->for($alice)->create(['body' => 'shipping this quarter']);

    return ['owner' => $alice, 'team' => $team, 'channel' => $channel];
}

test('the delete-channel dialog passes the axe audit in either theme', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithDeletableChannel();

    $page = signInThroughBrowser($alice)
        ->navigate("/t/{$team->slug}/c/{$channel->slug}")
        ->click('@channel-options')
        ->click('@delete-channel')
        // The destruction summary is fetched when the dialog opens, so the audit
        // waits for the resolved counts rather than the "Counting…" placeholder.
        ->assertPresent('[data-test="delete-channel-summary"]')
        // The dialog fades and zooms in; auditing mid-transition samples the
        // interpolated colors rather than the settled tokens.
        ->wait(0.5)
        ->assertNoAccessibilityIssues();

    switchToDarkTheme($page)
        ->assertNoAccessibilityIssues();
});

test('the recently-deleted panel passes the axe audit in either theme', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithDeletableChannel();

    app(DeleteChannel::class)->handle($channel, null);

    $page = signInThroughBrowser($alice)
        ->navigate("/settings/teams/{$team->slug}/deleted-channels")
        ->assertPresent('[data-test="deleted-channel-row-roadmap"]')
        ->assertNoAccessibilityIssues();

    switchToDarkTheme($page)
        ->assertNoAccessibilityIssues();
});
