<?php

declare(strict_types=1);

use App\Enums\PresenceState;
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
 * So, before adding one: does the verb claim a `ShortcutId`? Then nothing at
 * all — the derivation guard covers it and the shortcut suite is its behaviour.
 * Does it open a dialog, pin a `?nav=` destination, carry an `isAvailable`, or
 * write the theme? Then a unit test only: those four seams are proven below.
 * Does it do something none of the four covers? That is a new seam, and it earns
 * the fifth test here.
 *
 * The selectors stay `@quick-switcher-*`: the surface was renamed, its 96
 * opaque selectors were deliberately not.
 */

/**
 * Whether focus sits somewhere inside the dialog holding the given control.
 *
 * Read once the palette has gone rather than while it is going: the palette's
 * own focus restore fires on its teardown (see `DialogFocusRestoreTest`), so a
 * reading taken before that would pass on a shell that yanks focus back to the
 * trigger a frame later.
 */
function focusIsInsideTheDialogHolding(string $test): string
{
    return <<<JS
    (() => {
        const dialog = document
            .querySelector('[data-test="{$test}"]')
            ?.closest('[role="dialog"]');

        return Boolean(dialog?.contains(document.activeElement));
    })()
    JS;
}

/**
 * A script resolving once the document element carries the dark palette.
 *
 * The write is synchronous inside `run`, but the click that reaches it is not:
 * `assertScript` is a one-shot evaluation with no retry (#947), so the frames
 * between the row being pressed and reka answering its `select` are polled out
 * rather than slept through.
 */
function documentGoesDark(): string
{
    return <<<'JS'
    (async () => {
        const dark = () => document.documentElement.classList.contains('dark');

        for (let frame = 0; frame < 120 && ! dark(); frame++) {
            await new Promise(requestAnimationFrame);
        }

        return dark();
    })()
    JS;
}
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

test('a command opening a dialog leaves focus inside it, not back on the trigger', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $channel))
        ->click('@quick-switcher-trigger')
        ->click('@quick-switcher-new-message')
        // Two entries of one `DialogHost` mid-transition: the palette dismisses
        // as the picker mounts in its place. Waiting for the palette to be gone
        // is what puts its focus restore behind us — the reading below is of the
        // settled shell, not of the frame the handover happened on.
        ->assertNotPresent('@quick-switcher-input')
        ->assertPresent('@new-dm-input')
        ->assertScript(geometrySettles(elementBox('[data-test="new-dm-input"]')), true)
        ->assertScript(focusIsInsideTheDialogHolding('new-dm-input'), true);
});

test('the availability predicates read prop paths a real page carries', function (): void {
    ['owner' => $alice, 'member' => $bob, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    // Six rows, four distinct prop paths: `currentTeam` for the first two,
    // `auth.user.dnd` for the resume, `canInviteToCurrentTeam` for the invite,
    // `auth.user.presence` for the presence pair. All four fail the same silent
    // way — a path that is not there reads `undefined`, falls to false, and the
    // row vanishes for everyone — and a unit stub written from the same wrong
    // path agrees with it. Only a real page can say the strings are right.
    //
    // The manual override is what makes Alice away here, because it wins over
    // the connection roster outright; Bob is left alone and reads active.
    $alice->forceFill([
        'dnd_until' => now()->addMinutes(30),
        'presence_state' => PresenceState::Away,
    ])->save();

    signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $channel))
        ->click('@quick-switcher-trigger')
        ->assertPresent('@quick-switcher-new-message')
        ->assertPresent('@quick-switcher-browse-channels')
        ->assertPresent('@quick-switcher-invite-people')
        ->assertPresent('@quick-switcher-resume-notifications')
        // The presence pair is one capability split across two rows, so what is
        // proven is that only ever one of them is on the page (#1224).
        ->assertPresent('@quick-switcher-set-presence-active')
        ->assertNotPresent('@quick-switcher-set-presence-away')
        // The other half of the gate: the modal is mounted only for a viewer who
        // may invite, so a row that renders has to reach something listening.
        ->click('@quick-switcher-invite-people')
        ->assertPresent('@invite-submit');

    // The opposite polarity on the same page, so a predicate stuck true is told
    // apart from one stuck false: a plain member, with nothing paused.
    signInThroughBrowser($bob)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $channel))
        ->click('@quick-switcher-trigger')
        ->assertPresent('@quick-switcher-new-message')
        ->assertPresent('@quick-switcher-browse-channels')
        // Absent, never disabled: the list is the set of things this viewer can
        // do rather than a list of things they cannot.
        ->assertNotPresent('@quick-switcher-invite-people')
        ->assertNotPresent('@quick-switcher-resume-notifications')
        ->assertPresent('@quick-switcher-set-presence-away')
        ->assertNotPresent('@quick-switcher-set-presence-active');
});

test('a theme command repaints the document', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    // One row of the trio, because light and system are this code path with a
    // different argument — and *system* answers to the harness's
    // `prefers-color-scheme`, which is a Playwright question rather than a
    // palette one. Nothing else in the repo switches appearance through the UI:
    // every audit wanting dark forces it (see `switchToDarkTheme()`), which is
    // why the one real switch earns a browser test.
    signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $channel))
        ->click('@quick-switcher-trigger')
        ->click('@quick-switcher-theme-dark')
        ->assertScript(documentGoesDark(), true);
});
