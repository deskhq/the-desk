<?php

declare(strict_types=1);

use App\Models\Message;
use App\Models\MessageReminder;
use App\Models\User;

// A toast's action has to be reachable before its timer runs out, and reaching
// for the mouse is not a way to reach it. F6 takes focus to the bottom-right
// rail — to whichever cards are on it, since the rail is one surface shared by
// the toasts and the reminder nudges (#979).

/**
 * Press F6 on whatever holds focus, so it bubbles the way a real keystroke does
 * — past sonner's listener on `document` and on to the app's own on `window`.
 * `code` as well as `key`, since the two listeners read different fields.
 */
function browserPressF6(): string
{
    return <<<'JS'
    () => {
        (document.activeElement ?? document.body).dispatchEvent(
            new KeyboardEvent('keydown', {
                key: 'F6',
                code: 'F6',
                bubbles: true,
            }),
        );
    }
    JS;
}

/** The `data-test` of whatever currently holds focus, or the empty string. */
function browserFocusedTestId(): string
{
    return <<<'JS'
    (() => document.activeElement?.closest('[data-test]')?.dataset.test ?? '')()
    JS;
}

test('F6 focuses the toast region and holds its timer there', function (): void {
    $alice = User::factory()->create();

    // Saving the profile flashes a success toast from the server, which is the
    // plainest way to get a real one on the rail.
    $page = signInThroughBrowser($alice)
        ->navigate('/settings/profile')
        ->assertSee('Profile')
        ->click('[data-test="update-profile-button"]')
        ->assertPresent('[data-test=toast]');

    $page->script(browserPressF6());

    // Asserted in one read: sonner's list takes the focus, and the drain holds
    // where it is. The drain is driven by the same expansion that pauses the
    // dismiss timer, so a toast reached this way waits to be acted on. Both are
    // read at once because a retry loop here outlives the toast it is asking
    // about.
    $page->assertScript(<<<'JS'
    (() => {
        const focused = document.activeElement?.hasAttribute('data-sonner-toaster') ?? false;
        const drain = document.querySelector('[data-test=toast-drain] > span');

        return `${focused}|${drain?.style.animationPlayState ?? 'missing'}`;
    })()
    JS, 'true|paused');
});

test('F6 leaves focus alone when the rail is empty', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $page = signInThroughBrowser($alice)
        ->assertPresent('@message-composer-input')
        ->click('@message-composer-input')
        ->type('@message-composer-input', 'still typing');

    $page->script(browserPressF6());

    // Nothing is on the rail, so the composer keeps the caret rather than
    // losing it to an empty corner.
    $page->assertScript(browserFocusedTestId(), 'message-composer-input');
});

test('F6 goes to the reminder nudges when one is on the rail', function (): void {
    ['owner' => $alice, 'channel' => $channel] = browserTeamWithChannel();

    $message = Message::factory()->for($channel)->for($alice, 'user')->create([
        'body' => 'The message being reminded about',
    ]);
    MessageReminder::factory()->fired()->create([
        'user_id' => $alice->id,
        'message_id' => $message->id,
        'remind_at' => now()->subMinute(),
    ]);

    $page = signInThroughBrowser($alice)
        ->assertPresent('[data-test=reminder-nudges]');

    // From inside the composer: a shortcut suppressed while typing could never
    // reach a card raised by the send the user just made.
    $page->click('@message-composer-input')
        ->type('@message-composer-input', 'mid-sentence')
        ->script(browserPressF6());

    $page->assertScript(browserFocusedTestId(), 'reminder-nudges');

    // A named region, so landing there announces what it is rather than
    // dropping a screen-reader user into an unlabelled box — and a name on a
    // role that can carry one, which a bare div could not.
    //
    // An axe audit belongs here too, but the nudge card already carries a
    // serious contrast failure of its own (#1009) and would fail it on arrival.
    $page->assertAttribute('[data-test=reminder-nudges]', 'role', 'region')
        ->assertAttribute('[data-test=reminder-nudges]', 'aria-label', 'Reminders');
});
