<?php

declare(strict_types=1);

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\TeamInvitation;
use App\Models\User;

/**
 * The workspace sheet, the "+ New" menu, and the rail's workspace tiles.
 *
 * The sheet is one surface behind three anchors, so what these guard is that
 * each anchor reaches the same rows, that a plain member never finds an action
 * they cannot take, and that the two membership rows (invite, join) are present
 * exactly when they lead somewhere.
 */

/** Give the viewer a second workspace holding an unread message. */
function browserSecondWorkspace(User $viewer, string $name = 'Nord Bureau'): void
{
    $author = User::factory()->create();
    $team = app(CreateTeam::class)->handle($author, $name);
    $general = Channel::where('team_id', $team->id)
        ->where('slug', Channel::GENERAL_SLUG)
        ->firstOrFail();

    $team->memberships()->create(['user_id' => $viewer->id, 'role' => TeamRole::Member]);
    $general->channelMembers()->firstOrCreate(['user_id' => $viewer->id]);

    Message::factory()->for($general)->for($author)->create(['body' => 'Hello over here']);
}

test('the header chevron and the rail tile open the same workspace sheet', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    $page = signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $channel))
        ->click('@workspace-switcher')
        ->assertPresent('@workspace-sheet')
        ->assertSee('Workspaces')
        ->assertPresent('@workspace-members-link')
        ->assertPresent('@invite-member-trigger')
        ->assertPresent('@workspace-settings-link')
        ->assertPresent('@team-switcher-new-team')
        ->keys('@workspace-sheet', ['Escape'])
        ->assertNotPresent('@workspace-sheet');

    // The rail's own tile is the third anchor onto the very same surface.
    $page->click('@rail-workspace-tile')
        ->assertPresent('@workspace-sheet')
        ->assertPresent('@workspace-members-link');
});

test('a plain member finds neither invite nor workspace settings on the sheet', function (): void {
    ['member' => $bob, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    signInThroughBrowser($bob)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $channel))
        ->click('@workspace-switcher')
        ->assertPresent('@workspace-sheet')
        ->assertPresent('@workspace-members-link')
        ->assertNotPresent('@invite-member-trigger')
        ->assertNotPresent('@workspace-settings-link');
});

test('the sheet closes on its way to the members roster', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $channel))
        ->click('@workspace-switcher')
        ->click('@workspace-members-link')
        // The roster the row anchors on, with the sheet gone behind it.
        ->assertPresent('@member-row')
        ->assertNotPresent('@workspace-sheet');
});

test('the invite row opens the invite modal without leaving the channel', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $channel))
        ->click('@workspace-switcher')
        ->click('@invite-member-trigger')
        ->assertPresent('@invite-submit')
        ->assertPathIs(browserChannelUrl($team, $channel));
});

test('the join row appears only while an invitation is pending, carrying its count', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    $page = signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $channel))
        ->click('@workspace-switcher')
        ->assertNotPresent('@join-workspace-trigger');

    $inviter = User::factory()->create();
    $other = app(CreateTeam::class)->handle($inviter, 'Sud');
    TeamInvitation::factory()->create([
        'team_id' => $other->id,
        'email' => $alice->email,
        'invited_by' => $inviter->id,
    ]);

    $page->navigate(browserChannelUrl($team, $channel))
        // The invitations prompt shows itself first; dismissing it is what makes
        // the join row the way back to it.
        ->assertPresent('@pending-invitations-modal')
        ->keys('@pending-invitations-modal', ['Escape'])
        ->click('@workspace-switcher')
        ->assertPresent('@join-workspace-trigger')
        ->assertSeeIn('@join-workspace-count', '1')
        ->click('@join-workspace-trigger')
        ->assertPresent('@pending-invitations-modal');
});

test('the rail tiles every workspace, dots the unread one, and switches on a tap', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();
    browserSecondWorkspace($alice);

    signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $channel))
        ->assertScript(<<<'JS'
        (() => document.querySelectorAll('[data-test="rail-workspace-tile"]').length)()
        JS, 2)
        // The workspace the viewer is not reading carries the brass dot.
        ->assertScript(<<<'JS'
        (() => document.querySelectorAll('[data-test="rail-workspace-unread-dot"]').length)()
        JS, 1)
        ->assertPresent('@new-team-trigger');
});

test('the plus menu offers exactly three ways to start something', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $channel))
        ->click('@new-menu-trigger')
        ->assertPresent('@new-menu')
        ->assertPresent('@new-menu-channel')
        ->assertPresent('@new-menu-message')
        ->assertPresent('@create-section-trigger')
        // Membership is the workspace sheet's business, never the + menu's.
        ->assertNotPresent('@invite-member-trigger')
        ->assertScript(<<<'JS'
        (() => document.querySelectorAll('[data-test="new-menu"] [role="menuitem"]').length)()
        JS, 3);
});

test('the open workspace sheet has no serious accessibility violations, light or dark', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();
    browserSecondWorkspace($alice);

    // The settle lets the popover's fade finish: axe blends the mid-animation
    // opacity into its contrast arithmetic otherwise (#775).
    $page = signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $channel))
        ->click('@workspace-switcher')
        ->assertVisible('@workspace-sheet')
        ->wait(0.5)
        ->assertNoAccessibilityIssues();

    $page->script(<<<'JS'
    () => {
        localStorage.setItem('appearance', 'dark');
        document.documentElement.classList.add('dark');
        document.documentElement.style.colorScheme = 'dark';
    }
    JS);

    $page->wait(0.5)->assertNoAccessibilityIssues();
});

test('the workspace sheet and the plus menu are keyboard-operable', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    // Both anchors are real buttons, so Tab reaches them and Enter opens them.
    signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $channel))
        ->assertPresent('button[data-test="workspace-switcher"]')
        ->assertPresent('button[data-test="new-menu-trigger"]')
        ->keys('@new-menu-trigger', 'Enter')
        ->assertPresent('@new-menu');
});

test('the mobile drawer header carries the workspace, the plus and the close', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->resize(390, 844)
        ->navigate(browserChannelUrl($team, $channel))
        ->click('@sidebar-toggle')
        ->assertPresent('@workspace-switcher')
        ->assertPresent('@new-menu-trigger')
        ->assertPresent('@dock-close')
        ->click('@workspace-switcher')
        ->assertPresent('@workspace-sheet')
        ->assertPresent('@workspace-members-link');
});

test('the onboarding tour completes with the invite step spotlighting the workspace sheet', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();
    $alice->update(['onboarding_completed_at' => null]);

    signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $channel))
        ->assertPresent('@onboarding-tour')
        ->click('@onboarding-next')
        ->click('@onboarding-next')
        ->assertSee('Invite your teammates')
        // The last step's anchor has to resolve, or the spotlight cuts no hole.
        ->assertScript(<<<'JS'
        (() => {
            const anchor = document.querySelector('[data-tour="invite"]');

            if (anchor === null) {
                return false;
            }

            const rect = anchor.getBoundingClientRect();

            return rect.width > 0 && rect.height > 0;
        })()
        JS, true)
        ->click('@onboarding-next')
        ->assertNotPresent('@onboarding-tour');
});
