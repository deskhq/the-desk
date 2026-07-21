<?php

declare(strict_types=1);

use App\Models\Message;

/**
 * The two surfaces that dropped `<input type="date">` for the shadcn date
 * picker. Both are driven through the real popover + calendar, because the
 * behaviour that matters — a picked day reaching the model as an ISO string —
 * only exists once the portalled calendar renders in a browser.
 */
test('the audit export period is picked from a calendar and validates its order', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    // Reached through the settings sidebar — client-side Inertia visits, so the
    // browser session survives (a full navigate() would drop it).
    $page = signInThroughBrowser($alice)
        ->click('@sidebar-menu-button')
        ->assertPresent('@settings-menu-item')
        // Let the dropdown settle past its open/pointer-grace window, otherwise
        // the item click can be swallowed and never navigate.
        ->wait(0.5)
        ->click('@settings-menu-item')
        ->assertPathContains('/settings')
        ->click('@settings-nav-exports')
        ->assertPathContains('/exports')
        ->assertPresent('[data-test="audit-export-form"]')
        // No native date input survives on the page.
        ->assertMissing('input[type="date"]')
        // The start trigger opens a calendar; picking today fills the field.
        ->click('@audit-export-range-start')
        ->wait(0.3)
        ->assertPresent('[data-reka-calendar-cell-trigger]')
        ->click('[data-reka-calendar-cell-trigger][data-today]')
        ->wait(0.3)
        // A picked day swaps the placeholder for the formatted date, and the
        // clear control appears now that the period is no longer empty.
        ->assertPresent('[data-test="audit-export-range-clear"]');

    // The end picker cannot reach a day before the start: reka-ui disables
    // everything under `min`, so the order can't be broken from the calendar.
    $page->click('@audit-export-range-end')
        ->wait(0.3)
        ->assertPresent('[data-reka-calendar-cell-trigger][data-disabled]')
        ->click('[data-reka-calendar-cell-trigger][data-today]')
        ->wait(0.3)
        // A same-day period is valid, so the export stays submittable.
        ->assertMissing('[data-test="audit-export-range-error"]');

    // Clearing empties both ends and takes the clear control away with them.
    $page->click('@audit-export-range-clear')
        ->wait(0.3)
        ->assertMissing('[data-test="audit-export-range-clear"]')
        ->assertNoAccessibilityIssues();

    // Re-audit the open calendar against the dark palette (localStorage before
    // the class, so the appearance controller doesn't re-resolve to light
    // mid-audit) — the popover carries its own surface and border tokens.
    $page->script(<<<'JS'
    () => {
        localStorage.setItem('appearance', 'dark');
        document.documentElement.classList.add('dark');
        document.documentElement.style.colorScheme = 'dark';
    }
    JS);

    $page->wait(0.5)
        ->assertPresent('html.dark')
        ->click('@audit-export-range-start')
        ->wait(0.3)
        ->assertPresent('[data-reka-calendar-cell-trigger]')
        ->assertNoAccessibilityIssues();
});

test('the search custom range picks its bounds from a calendar', function (): void {
    ['owner' => $alice, 'channel' => $channel, 'member' => $bob] = browserTeamWithChannel();

    Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $bob->id,
        'body' => 'the quokka danced at dawn today',
    ]);

    signInThroughBrowser($alice)
        ->click('@masthead-search')
        ->assertPathContains('/search')
        ->type('@search-input', 'quokka')
        ->wait(0.8)
        ->assertPresent('[data-test="search-result"]')
        ->assertMissing('input[type="date"]')
        // Open the date facet and reveal the custom range.
        ->click('@facet-date-picker')
        ->wait(0.3)
        ->click('@facet-date-custom')
        ->wait(0.3)
        ->assertPresent('[data-test="facet-date-after"]')
        // The nested calendar opens without dismissing the facet popover — the
        // reason this facet is a Popover rather than a DropdownMenu.
        ->click('@facet-date-after')
        ->wait(0.3)
        ->assertPresent('[data-reka-calendar-cell-trigger]')
        ->click('[data-reka-calendar-cell-trigger][data-today]')
        ->wait(0.8)
        // The picked bound applies as the date chip, exactly as a preset does.
        ->assertPresent('[data-test="facet-date"]')
        ->assertNoAccessibilityIssues();
});
