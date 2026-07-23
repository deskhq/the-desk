<?php

declare(strict_types=1);

use App\Enums\TimeFormat;

/**
 * The clock-style preference re-renders every time of day live. Reaching the
 * settings pages through the nav keeps the visits client-side (a hard navigate
 * would drop the browser session), matching SidebarPositionTest.
 *
 * The quiet-hours bounds are the sharpest witness: they used to be hardcoded to
 * a 24-hour frame while the paused card two screens away spoke 12-hour, so an
 * English viewer configured the window in one convention and was told about it
 * in the other.
 */
test('a user pins a 24-hour clock and the quiet-hours bounds follow it', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $alice->forceFill([
        'time_format' => TimeFormat::Auto->value,
        'dnd_schedule_enabled' => true,
        'dnd_starts_at' => '18:00',
        'dnd_ends_at' => '09:00',
    ])->save();

    signInThroughBrowser($alice)
        ->click('@sidebar-menu-button')
        ->assertPresent('@settings-menu-item')
        // Let the dropdown settle past its open/pointer-grace window.
        ->wait(0.5)
        ->click('@settings-menu-item')
        ->assertPathContains('/settings')
        ->click('@settings-nav-appearance')
        ->assertPathContains('/settings/appearance')
        // English on Auto reads a 12-hour clock, as it always has.
        ->assertSeeIn('@quiet-hours-starts-at', '6:00 PM')
        ->click('@settings-nav-language')
        ->assertPathContains('/settings/language')
        // The clock-style select is a second labelled control on the page; axe
        // guards its label/role wiring the way the language select above it is.
        ->assertNoAccessibilityIssues()
        ->click('@time-format')
        ->assertPresent('@time-format-24h')
        ->click('@time-format-24h')
        ->click('@settings-nav-appearance')
        ->assertPathContains('/settings/appearance')
        // The stored bound is unchanged — only the label it renders under.
        ->assertSeeIn('@quiet-hours-starts-at', '18:00');

    expect($alice->refresh()->time_format)->toBe(TimeFormat::TwentyFourHour);
    expect($alice->dnd_starts_at)->toBe('18:00');
});
