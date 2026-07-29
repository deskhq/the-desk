<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;

/**
 * The workspace settings page's axe coverage (#1019).
 *
 * Three icon-only controls live here — transfer ownership, remove member, and
 * cancel invitation — and all three shipped with no accessible name because no
 * audit ever visited the page. Seeding a second member and a pending invitation
 * is what puts each of them on screen: the owner row carries neither owner-level
 * control, and the invitations section only renders when one is outstanding.
 *
 * @return array{owner: User, team: Team}
 */
function browserTeamWithPendingInvitation(): array
{
    ['owner' => $alice, 'team' => $team] = browserTeamWithChannel();

    TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'grace@example.com',
        'invited_by' => $alice->id,
    ]);

    return ['owner' => $alice, 'team' => $team];
}

test('the workspace settings page passes the axe audit in either theme', function (): void {
    ['owner' => $alice, 'team' => $team] = browserTeamWithPendingInvitation();

    $page = signInThroughBrowser($alice)
        ->navigate("/settings/teams/{$team->slug}")
        ->assertSee('Team members')
        ->assertPresent('[data-test="member-transfer-ownership-button"]')
        ->assertPresent('[data-test="member-remove-button"]')
        ->assertPresent('[data-test="invitation-cancel-button"]')
        ->assertNoAccessibilityIssues();

    switchToDarkTheme($page)
        ->assertNoAccessibilityIssues();
});

/**
 * The demo locks both owner-level controls, and the lock is the harder half of
 * the accessibility story: a `disabled` button drops out of the tab order, so
 * the tooltip explaining *why* it is locked would only ever reach a mouse. The
 * controls are marked `aria-disabled` instead, which is what programmatic focus
 * proves here — a disabled button refuses focus, an `aria-disabled` one takes it.
 */
test('the demo-locked controls stay focusable, named, and axe-clean', function (): void {
    config(['demo.mode' => true]);

    ['owner' => $alice, 'team' => $team] = browserTeamWithPendingInvitation();

    $page = signInThroughBrowser($alice)
        ->navigate("/settings/teams/{$team->slug}")
        ->assertSee('Team members')
        ->assertPresent('[data-test="member-transfer-ownership-button"]')
        // Nothing has been focused yet, so no tooltip is open — which is what
        // makes the same assertion below meaningful.
        ->assertDontSee('Disabled in the demo');

    $page->assertScript(<<<'JS'
    (() => {
        const named = {
            'member-transfer-ownership-button': 'Transfer ownership',
            'member-remove-button': 'Remove member',
        };

        return Object.entries(named).every(([selector, name]) => {
            const control = document.querySelector(`[data-test="${selector}"]`);

            control.focus();

            return document.activeElement === control
                && control.getAttribute('aria-label') === name
                && control.getAttribute('aria-disabled') === 'true';
        });
    })()
    JS, true);

    // The point of keeping them focusable: the tooltip's reason now reaches a
    // keyboard, where the previous `disabled` control only ever answered a hover.
    $page->assertSee('Disabled in the demo');

    $page->assertNoAccessibilityIssues();

    switchToDarkTheme($page)
        ->assertNoAccessibilityIssues();
});
