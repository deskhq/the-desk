<?php

declare(strict_types=1);

use App\Models\Channel;
use App\Models\Message;

// A forward lands in a channel the sender is not looking at, and deliberately
// does not take them there. The confirmation toast can still offer Undo because
// the response flashes the copy it created; pressing it deletes that copy in its
// own channel while leaving the sender exactly where they were (#979).

test('a forward can be undone from its toast without leaving the channel', function (): void {
    ['owner' => $alice, 'team' => $team] = browserTeamWithChannel();

    $elsewhere = Channel::factory()->for($team)->create(['name' => 'random']);
    $elsewhere->members()->attach($alice->id);

    $body = 'A message forwarded by mistake';

    $page = signInThroughBrowser($alice)
        ->assertPresent('@message-composer-input')
        ->type('@message-composer-input', $body)
        ->click('@message-composer-send')
        ->assertSee($body);

    // Forward it to #random, narrowing the destination list to one option so the
    // click cannot land on the wrong channel.
    $page->hover('@message-body')
        ->click('@message-forward')
        ->type('[data-test=forward-channel-search]', 'random')
        ->click('[data-test=forward-channel-option]')
        ->click('[data-test=forward-submit]')
        ->assertSee('Message forwarded to #random')
        ->assertSeeIn('[data-test=toast-action]', 'Undo');

    $forwarded = Message::where('channel_id', $elsewhere->id)->firstOrFail();

    $page->click('[data-test=toast-action]');

    // The copy goes, in the channel it landed in.
    //
    // Waited on through the page rather than with a sleep, and that is the whole
    // point of the loop: the application is served from an Amp server inside this
    // very process, whose event loop only runs while the PHP side awaits. A
    // blocking sleep here does not merely fail to help — it is what stops the
    // DELETE the click issued from ever being read off the socket, so the poll
    // spends its whole budget preventing the thing it is waiting for. The test
    // passed anyway on an unloaded machine, where the request completes inside
    // the tail of `click()`'s own await, and failed on CI where it does not
    // (#1077). `$page->wait()` yields to that loop instead, and
    // `tests/Unit/BrowserSuiteEventLoopTest.php` keeps a sleep from coming back
    // here or anywhere else in the suite.
    $deleted = false;

    for ($attempt = 0; $attempt < 50 && ! $deleted; $attempt++) {
        $page->wait(0.1);
        $deleted = Message::withTrashed()->find($forwarded->id)?->trashed() ?? false;
    }

    expect($deleted)->toBeTrue();

    // And the sender is still in #general, not dropped into the destination the
    // destroy route redirects to. The row above only proves the server ran the
    // delete, so give the response a beat to reach the browser first — a URL
    // assertion is a one-shot read with no retry of its own.
    $page->wait(0.5)
        ->assertPathIs("/t/{$team->slug}/c/general")
        ->assertSee($body);
});
