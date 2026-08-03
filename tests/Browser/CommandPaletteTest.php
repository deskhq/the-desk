<?php

declare(strict_types=1);

use App\Models\Message;
use App\Models\MessageReminder;

/**
 * The seams a command crosses on its way out of the palette (#1217).
 *
 * Deliberately per-seam rather than per-verb: a command is a static entry in one
 * registry, so what can be wrong is the *kind* of thing its `run` does, and the
 * kinds are finite. Whoever adds the seventeenth verb answers one question —
 * does it cross a seam already proven here? — and writes a test only when the
 * answer is no. Four tests in one file is that rule, written down as code.
 *
 * The `?nav=` pin is the seam this child owns; the rest arrive with the next one.
 * The selectors stay `@quick-switcher-*`: the surface was renamed, its 96
 * opaque selectors were deliberately not.
 */
test('a command pinning a destination lands it on the current route', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    $message = Message::factory()->for($channel)->for($alice, 'user')->create([
        'body' => 'Review the proposal',
    ]);
    $reminder = MessageReminder::factory()->create([
        'user_id' => $alice->id,
        'message_id' => $message->id,
        'remind_at' => now()->subDay(),
    ]);

    signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $channel))
        ->click('@quick-switcher-trigger')
        ->click('@quick-switcher-reminders')
        // The verb only writes the URL: what proves the seam is the dock's own
        // watcher adopting the destination off it, exactly as a deep link does.
        ->assertPresent('@destination-panel-reminders')
        ->assertPresent('[data-reminder="'.$reminder->id.'"]')
        // That write is a client-side visit whose history entry lands a frame
        // later, so settle before reading the URL (#937).
        ->assertScript(queryParamSettles('nav', 'reminders'), true)
        ->assertQueryStringHas('nav', 'reminders');
});
