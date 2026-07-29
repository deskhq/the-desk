<?php

declare(strict_types=1);

use App\Models\Channel;
use App\Models\Message;
use Illuminate\Support\Sleep;

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
    $deleted = false;

    for ($attempt = 0; $attempt < 50 && ! $deleted; $attempt++) {
        Sleep::usleep(100_000);
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
