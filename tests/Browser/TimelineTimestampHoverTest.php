<?php

declare(strict_types=1);

use App\Models\Message;
use App\Models\User;

/**
 * Two of Bob's messages a minute apart collapse into a single author group, so
 * the timeline shows one always-visible lead time under the avatar plus a
 * per-message time revealed on hover in the same gutter.
 *
 * @return array{owner: User, lead: Message, follow: Message}
 */
function timelineAuthorGroup(): array
{
    ['owner' => $alice, 'member' => $bob, 'channel' => $channel] = browserTeamWithChannel();

    $lead = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $bob->id,
        'body' => 'Kickoff at nine',
        'created_at' => now()->subMinutes(2),
    ]);

    // Same author, well within the grouping window, so it folds under the lead
    // rather than starting a fresh group with its own avatar and lead time.
    $follow = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $bob->id,
        'body' => 'Bring the deck',
        'created_at' => now()->subMinute(),
    ]);

    return ['owner' => $alice, 'lead' => $lead, 'follow' => $follow];
}

test('hovering a later message reveals its timestamp but hovering the lead does not duplicate the group time', function (): void {
    ['owner' => $alice, 'lead' => $lead, 'follow' => $follow] = timelineAuthorGroup();

    signInThroughBrowser($alice)
        ->assertSee('Kickoff at nine')
        ->assertSee('Bring the deck')
        // A non-lead row carries its own gutter <time>, hidden at rest and faded
        // in on hover. The 0.4s wait clears the 150ms opacity transition.
        ->hover("#message-{$follow->id}")
        ->wait(0.4)
        ->assertScript(
            "getComputedStyle(document.querySelector('#message-{$follow->id} > time')).opacity",
            '1',
        )
        // The lead row already shows the group time under the avatar, so hovering
        // it must not fade in a second, overlapping timestamp: its <time> stays
        // fully transparent even while the row is hovered.
        ->hover("#message-{$lead->id}")
        ->wait(0.4)
        ->assertScript(
            "getComputedStyle(document.querySelector('#message-{$lead->id} > time')).opacity",
            '0',
        );
});

test('every message row keeps a machine-readable time regardless of the hover treatment', function (): void {
    ['owner' => $alice, 'lead' => $lead, 'follow' => $follow] = timelineAuthorGroup();

    // The lead row no longer reveals its hover time, but it must still carry the
    // <time datetime> element so the row stays machine-readable like the rest.
    signInThroughBrowser($alice)
        ->assertSee('Kickoff at nine')
        ->assertPresent("#message-{$lead->id} > time[datetime]")
        ->assertPresent("#message-{$follow->id} > time[datetime]");
});
