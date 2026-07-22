<?php

declare(strict_types=1);

test('a user pauses notifications from the presence menu and resumes in place', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $page = signInThroughBrowser($alice)
        ->click('@sidebar-menu-button')
        ->assertPresent('@pause-notifications-menu-item')
        // The flyout offers the presets; choosing one applies in place.
        ->click('@pause-notifications-menu-item')
        ->assertPresent('@pause-notifications-submenu')
        ->click('@pause-preset-thirty-minutes')
        ->wait(0.5);

    expect($alice->refresh()->dnd_until)->not->toBeNull()
        ->and($alice->isDndActive())->toBeTrue();

    // The STATUS section now leads with the paused card — crescent, lapse in
    // italic serif, Resume pill — and the masthead names the state.
    $page->assertPresent('@dnd-paused-card')
        ->assertPresent('@dnd-paused-until')
        ->assertSee('Notifications paused')
        // Resuming from the pill collapses the card without closing the menu.
        ->click('@dnd-resume-menu-item')
        ->wait(0.5)
        ->assertNotPresent('@dnd-paused-card')
        ->assertPresent('@pause-notifications-menu-item');

    expect($alice->refresh()->dnd_until)->toBeNull()
        ->and($alice->isDndActive())->toBeFalse();
});

test('the custom pause dialog stores the picked instant', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->click('@sidebar-menu-button')
        ->click('@pause-notifications-menu-item')
        ->assertPresent('@pause-notifications-submenu')
        ->click('@pause-preset-custom')
        ->assertPresent('@dnd-pause-dialog')
        // The controls open seeded on the next 5-minute step, already valid.
        ->click('@dnd-pause-save')
        ->wait(0.5)
        ->assertNotPresent('@dnd-pause-dialog');

    expect($alice->refresh()->dnd_until)->not->toBeNull()
        ->and($alice->dnd_until->isFuture())->toBeTrue();
});

test('the paused shell with its crescent badge has no serious accessibility violations in either theme', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $alice->forceFill(['dnd_until' => now()->addMinutes(30)])->save();

    // Audited with the menu closed: an *open* reka dropdown marks the page
    // behind it aria-hidden while the skip link stays focusable, a pre-existing
    // shell-wide finding tracked in #730 that would drown this slice's surfaces.
    // The crescent badge on the sidebar chip is what this run guards.
    $page = signInThroughBrowser($alice)
        ->assertPresent(
            '[data-test="nav-user-presence"][data-dnd="true"]',
        )
        ->assertNoAccessibilityIssues();

    // Re-audit against the dark palette, persisting the choice so the
    // appearance controller doesn't revert it mid-audit.
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

test('the quiet hours settings section has no serious accessibility violations', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $alice->forceFill([
        'dnd_schedule_enabled' => true,
        'dnd_starts_at' => '18:00',
        'dnd_ends_at' => '09:00',
    ])->save();

    signInThroughBrowser($alice)
        ->navigate('/settings/appearance')
        ->assertPresent('@quiet-hours-enabled')
        ->assertPresent('@quiet-hours-starts-at')
        ->assertSee('crosses midnight')
        ->assertNoAccessibilityIssues();
});
