<?php

declare(strict_types=1);

use App\Actions\Channels\JoinChannel;
use App\Models\Channel;

/**
 * Sidebar rows fetch their channel before the click asks for it (#1256).
 *
 * What is asserted here is the seam and the outcome — the hover happens, the
 * click lands on the hovered channel — and never the timing. Whether the paint
 * beat a wall-clock number is a measurement, not a test: a duration assertion on
 * a shared runner is a flake that gets deleted within a month, and the deletion
 * takes the intent with it.
 */

/** A script resolving once the address bar has reached the given path. */
function channelPathSettles(string $path): string
{
    return <<<JS
    (async () => {
        const settled = () => location.pathname === '{$path}';

        for (let frame = 0; frame < 300 && ! settled(); frame++) {
            await new Promise(requestAnimationFrame);
        }

        return settled();
    })()
    JS;
}

test('hovering a channel row and then clicking it opens that channel', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $general] = browserTeamWithChannel();

    $design = Channel::factory()->for($team)->create([
        'name' => 'design',
        'slug' => 'design',
    ]);
    app(JoinChannel::class)->handle($design, $alice);

    $target = browserChannelUrl($team, $design);

    signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $general))
        ->assertPresent('@channel-name-design')
        // The hover is what fires the speculative visit on a pointer device;
        // the click that follows is served by it, or falls back to the wire.
        // Either way the navigation has to complete and land on the channel
        // that was hovered.
        ->hover('a[href$="/c/design"]')
        ->click('a[href$="/c/design"]')
        ->assertScript(channelPathSettles($target), true)
        ->assertPathIs($target)
        ->assertSee('design');
});
