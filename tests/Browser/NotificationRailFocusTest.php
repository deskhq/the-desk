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

/**
 * A workspace whose owner has two reminders already fired, so the rail carries
 * two nudges at once: one for a live message — the card in full, quote and
 * channel line and all — and one for a since-deleted message, which is the only
 * way its "message deleted" stub reaches the screen. Between the two, every
 * muted string the card can paint is up at the same time (#1009).
 */
function browserOwnerWithFiredNudges(): User
{
    ['owner' => $alice, 'channel' => $channel] = browserTeamWithChannel();

    $message = Message::factory()->for($channel)->for($alice, 'user')->create([
        'body' => 'The message being reminded about',
    ]);
    MessageReminder::factory()->fired()->create([
        'user_id' => $alice->id,
        'message_id' => $message->id,
        'remind_at' => now()->subMinute(),
    ]);

    $deleted = Message::factory()->for($channel)->for($alice, 'user')->create([
        'body' => 'The message that has since been deleted',
    ]);
    MessageReminder::factory()->fired()->create([
        'user_id' => $alice->id,
        'message_id' => $deleted->id,
        'remind_at' => now()->subMinutes(2),
    ]);
    $deleted->delete();

    return $alice;
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
    $alice = browserOwnerWithFiredNudges();

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
    $page->assertAttribute('[data-test=reminder-nudges]', 'role', 'region')
        ->assertAttribute('[data-test=reminder-nudges]', 'aria-label', 'Reminders');
});

test('a nudge on the rail passes the axe audit in either theme', function (): void {
    $alice = browserOwnerWithFiredNudges();

    // The slab inverts between themes — ink in light, paper in dark — so a
    // colour that clears the floor on one of them says nothing about the other.
    // Nothing audited a page with a nudge actually on the rail until now, which
    // is how a serious contrast failure went unseen (#1009).
    $page = signInThroughBrowser($alice)
        ->assertPresent('[data-test=reminder-nudge]')
        ->assertSee('in #general')
        ->assertSee('This message was deleted.')
        ->assertNoAccessibilityIssues();

    switchToDarkTheme($page)->assertNoAccessibilityIssues();
});
