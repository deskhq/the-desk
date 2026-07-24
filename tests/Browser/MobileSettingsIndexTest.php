<?php

declare(strict_types=1);

use App\Enums\AppLocale;

/**
 * Mobile settings index and stacked detail (#776).
 *
 * Below the `md` breakpoint the two-pane settings layout does not survive a
 * phone width, so settings opens as a full-screen index list instead: every
 * real settings page is a row that pushes a stacked detail screen, and the
 * detail's back chevron returns to the index. From md up the two-pane layout
 * is unchanged and the index quietly moves along to the profile pane.
 */
test('settings opens as the index list at a phone width', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->resize(390, 844)
        ->navigate('/settings')
        ->assertPresent('@settings-index')
        ->assertPresent('@settings-index-identity')
        ->assertPresent('@settings-index-status')
        ->assertPresent('@settings-index-row-profile')
        ->assertPresent('@settings-index-row-security')
        ->assertPresent('@settings-index-row-appearance')
        ->assertPresent('@settings-index-row-language')
        ->assertPresent('@settings-index-row-data-privacy')
        ->assertPresent('@settings-index-row-about')
        ->assertPresent('@settings-index-row-teams')
        ->assertPresent('@settings-index-logout')
        // The owner reaches the team-evidence surfaces from the workspace card.
        ->assertPresent('@settings-index-row-audit-log')
        ->assertPresent('@settings-index-row-security-log')
        ->assertPresent('@settings-index-row-exports')
        // The desktop side nav is not part of the phone layout — the dock
        // sheet is closed, so its contents are not even mounted.
        ->assertNotPresent('@settings-nav');
});

test('a plain member sees no team-admin rows on the index', function (): void {
    ['member' => $bob] = browserTeamWithChannel();

    signInThroughBrowser($bob)
        ->resize(390, 844)
        ->navigate('/settings')
        ->assertPresent('@settings-index-row-teams')
        ->assertNotPresent('@settings-index-row-audit-log')
        ->assertNotPresent('@settings-index-row-security-log')
        ->assertNotPresent('@settings-index-row-exports');
});

test('a row pushes its detail screen and back returns to the index', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->resize(390, 844)
        ->navigate('/settings')
        ->click('@settings-index-row-profile')
        ->assertPathIs('/settings/profile')
        ->assertVisible('@settings-detail-back')
        ->click('@settings-detail-back')
        ->assertPathIs('/settings')
        ->assertPresent('@settings-index');
});

test('a desktop visit to the index moves along to the profile pane', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate('/settings')
        ->assertPathIs('/settings/profile')
        ->assertVisible('@settings-nav');
});

test('the index reads in French at the narrowest width', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();
    $alice->update(['locale' => AppLocale::French]);

    signInThroughBrowser($alice)
        ->resize(360, 740)
        ->navigate('/settings')
        ->assertSee('Paramètres')
        ->assertSee('Apparence et notifications')
        ->assertSee('Données et confidentialité');
});
