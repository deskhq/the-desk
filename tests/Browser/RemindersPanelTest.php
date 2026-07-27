<?php

declare(strict_types=1);

use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageReminder;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * The Reminders destination: the pending list the dock's panel renders at
 * `?nav=reminders`, in place of the dialog it replaces (#940).
 *
 * The bucket arithmetic itself is unit-covered against a frozen clock
 * (`resources/js/lib/reminderTime.test.ts`); what these tests prove is the
 * wiring — that the shared props reach the panel, that a group heading and its
 * rows render, and that checking a row really clears the reminder rather than
 * merely striking it through.
 *
 * Every due time here is chosen to be drift-free whatever hour the suite runs
 * at: a day behind is always overdue, three days out always lands inside the
 * week, and a month out is always later.
 *
 * Text assertions are scoped to the panel: the reminded messages are posted in
 * the channel the panel floats over, so a page-wide `assertSee` would pass on
 * the timeline behind it whatever the panel did.
 */

/** Post a message in the channel and hang a reminder on it for the viewer. */
function reminderOn(
    Channel $channel,
    User $viewer,
    string $body,
    CarbonInterface $due,
): MessageReminder {
    $message = Message::factory()->for($channel)->for($viewer, 'user')->create([
        'body' => $body,
    ]);

    return MessageReminder::factory()->create([
        'user_id' => $viewer->id,
        'message_id' => $message->id,
        'remind_at' => $due,
    ]);
}

/** The selector for one reminder's row, so a cleared row can be waited out. */
function reminderRow(MessageReminder $reminder): string
{
    return '[data-reminder="'.$reminder->id.'"]';
}

/** Whether the panel itself — not the channel behind it — shows some text. */
function remindersPanelShows(string $text): string
{
    $needle = json_encode($text, JSON_THROW_ON_ERROR);

    return <<<JS
    (() => (document.querySelector('[data-test="destination-panel-reminders"]')?.textContent ?? '').includes({$needle}))()
    JS;
}

test('the panel groups pending reminders by how soon they are due', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    $overdue = reminderOn($channel, $alice, 'Review the proposal', now()->subDay());
    $thisWeek = reminderOn($channel, $alice, 'Prepare the onboarding', now()->addDays(3));
    $later = reminderOn($channel, $alice, 'Renew the certificate', now()->addMonth());

    signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $channel).'?nav=reminders')
        ->assertPresent('@destination-panel-reminders')
        // Overdue is derived from the instant, so it needs no column of its own.
        ->assertPresent('@reminders-group-overdue')
        ->assertPresent('@reminders-group-this-week')
        ->assertPresent('@reminders-group-later')
        ->assertNotPresent('@reminders-group-today')
        ->assertPresent(reminderRow($overdue))
        ->assertPresent(reminderRow($thisWeek))
        ->assertPresent(reminderRow($later))
        ->assertScript(remindersPanelShows('Review the proposal'), true);
});

test('checking a row clears its reminder and drops the count with it', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    $overdue = reminderOn($channel, $alice, 'Review the proposal', now()->subDay());
    $later = reminderOn($channel, $alice, 'Renew the certificate', now()->addMonth());

    signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $channel).'?nav=reminders')
        ->assertPresent(reminderRow($overdue))
        ->click('@reminder-clear')
        // The row is gone for good: "done" is the clear under a warmer name,
        // there being no completed state on the model until #524.
        ->assertNotPresent(reminderRow($overdue))
        ->assertNotPresent('@reminders-group-overdue')
        ->assertPresent(reminderRow($later))
        // And it was the reminder that went, not just its row: a cold load of
        // the same URL builds the list from the server's own props.
        ->navigate(browserChannelUrl($team, $channel).'?nav=reminders')
        ->assertPresent(reminderRow($later))
        ->assertNotPresent(reminderRow($overdue));
});

test('clear all empties the panel down to its empty state', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    reminderOn($channel, $alice, 'Review the proposal', now()->subDay());
    reminderOn($channel, $alice, 'Renew the certificate', now()->addMonth());

    signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $channel).'?nav=reminders')
        ->assertPresent('@reminders-list')
        ->click('@reminders-clear-all')
        ->assertPresent('@reminders-empty')
        ->assertNotPresent('@reminders-list')
        ->assertNotPresent('@reminders-count')
        // The workspace is genuinely empty of reminders, not just this render.
        ->navigate(browserChannelUrl($team, $channel).'?nav=reminders')
        ->assertPresent('@reminders-empty');
});

test('a reminder on a deleted message keeps its row and says so', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    $reminder = reminderOn($channel, $alice, 'the vanished message', now()->subDay());
    $reminder->message->delete();

    signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $channel).'?nav=reminders')
        ->assertPresent(reminderRow($reminder))
        ->assertScript(remindersPanelShows('This message was deleted.'), true)
        ->assertScript(remindersPanelShows('the vanished message'), false);
});

test('the quick switcher opens the destination rather than a dialog', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    $reminder = reminderOn($channel, $alice, 'Review the proposal', now()->subDay());

    signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $channel))
        ->click('@quick-switcher-trigger')
        ->click('@quick-switcher-reminders')
        ->assertPresent('@destination-panel-reminders')
        ->assertPresent(reminderRow($reminder))
        // The destination is pinned by a client-side visit whose history write
        // lands a frame later, so settle before reading the URL (#937).
        ->assertScript(queryParamSettles('nav', 'reminders'), true)
        ->assertQueryStringHas('nav', 'reminders');
});

test('the panel has no serious accessibility violations, light or dark', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    $reminder = reminderOn($channel, $alice, 'Review the proposal', now()->subDay());
    reminderOn($channel, $alice, 'Renew the certificate', now()->addMonth());

    // The rows are the part the gate cannot see: a group heading the design
    // tints warm, and a checkbox whose only name is its `aria-label`.
    $page = signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $channel).'?nav=reminders')
        ->assertPresent(reminderRow($reminder))
        ->assertNoAccessibilityIssues();

    // Re-audit against the dark palette; persisting to localStorage first keeps
    // the appearance controller from re-resolving 'system' back to light.
    $page->script(<<<'JS'
    () => {
        localStorage.setItem('appearance', 'dark');
        document.documentElement.classList.add('dark');
        document.documentElement.style.colorScheme = 'dark';
    }
    JS);

    $page->wait(0.5)
        ->assertNoAccessibilityIssues();
});

test('the destination opens in the mobile drawer as well', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    $reminder = reminderOn($channel, $alice, 'Review the proposal', now()->subDay());

    signInThroughBrowser($alice)
        ->resize(390, 844)
        ->navigate(browserChannelUrl($team, $channel))
        ->click('@sidebar-toggle')
        ->click('@tab-destination-reminders')
        ->assertPresent('@destination-panel-reminders')
        ->assertPresent(reminderRow($reminder))
        // The row stays comfortably inside the thumb's reach on a phone.
        ->assertScript(<<<'JS'
        (() => document.querySelector('[data-test="reminder-item"]').getBoundingClientRect().height >= 44)()
        JS, true);
});
