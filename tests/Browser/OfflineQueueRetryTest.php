<?php

declare(strict_types=1);

use App\Models\Message;
use Illuminate\Support\Str;

// Sends made while the socket is down are held in a per-channel outbox and
// flushed when it returns. Until #979 that flush was the only drain there was:
// a message whose flush failed was dropped, rolled back, and toasted one card
// per message, with nothing for the user to press. Now a failed flush puts the
// send back on the queue and says so once, carrying "Retry all".

test('a send made while the socket is down is queued rather than posted', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    // Point the browser's Echo at a port nothing is listening on, so the socket
    // never connects and the composer takes its offline path for the whole test.
    config(['broadcasting.connections.reverb.public_port' => 1]);

    signInThroughBrowser($alice)
        ->assertPresent('@message-composer-input')
        ->type('@message-composer-input', 'Held until the socket returns')
        ->click('@message-composer-send')
        ->assertPresent('[data-test=offline-queue-banner]')
        ->assertSee("You're offline. 1 message is queued and will send automatically.");
});

test('a failed flush is retryable from its toast and confirms when it lands', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    // The queued send quotes this message. Deleted, it fails `reply_to_id`
    // validation and so fails the flush; restored below, the same send lands —
    // which is what makes one retry meaningfully different from the last.
    $target = Message::factory()->for($channel)->for($alice)->create(['body' => 'The quoted message']);
    $target->delete();

    $queued = json_encode([[
        'clientUuid' => (string) Str::uuid(),
        'body' => 'Sent while the socket was down',
        'replyToId' => $target->id,
        'attachmentIds' => [],
    ]], JSON_THROW_ON_ERROR);

    $page = signInThroughBrowser($alice)->assertPresent('@message-composer-input');

    // Seed the persisted queue exactly as an offline session leaves it, then
    // load the channel so it rehydrates and flushes once the socket connects.
    $page->script("() => localStorage.setItem('outbox:{$channel->id}', '{$queued}')");

    $page = $page->navigate(browserChannelUrl($team, $channel))
        ->assertPresent('[data-test=toast]')
        ->assertSee("1 message didn't send")
        ->assertSeeIn('[data-test=toast-action]', 'Retry all');

    $target->restore();

    $page->click('[data-test=toast-action]')
        ->assertSee('Queued message sent')
        ->assertSee('Sent while the socket was down');

    expect(Message::where('body', 'Sent while the socket was down')->exists())->toBeTrue();
});
