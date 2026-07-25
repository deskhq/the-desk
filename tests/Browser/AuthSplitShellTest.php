<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;

/**
 * The bold ink/paper auth shell (#860), driven in a real browser.
 *
 * Two things here can only be caught this way: the invitation panel and locked
 * email field, which depend on shared page props reaching the layout, and the
 * password strength meter, whose zxcvbn dictionaries resolve to a different
 * module shape under the browser bundle than under Vitest.
 */
test('the login screen renders the split shell and still signs a user in', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    visit('/login')
        ->assertSee('Where the work actually')
        ->assertSee('gets said.')
        ->assertSee('Welcome back')
        ->assertSee('Keep me signed in')
        ->type('#email', $alice->email)
        ->type('#password', 'password')
        ->click('@login-button')
        ->assertPathIsNot('/login');
});

/**
 * Sequential focus order on the login form (#879). The "Forgot?" link is drawn
 * on the password label row, so it is easy to emit before the control and steal
 * the Tab stop the user is aiming for. Only a real browser resolves this: it is
 * the browser's own tab-order algorithm reading layout and DOM order, not
 * anything the component can assert about itself.
 */
test('tabbing out of the email field lands on the password input, not the reset link', function (): void {
    $page = visit('/login')
        ->keys('#email', ['Tab'])
        ->assertScript('document.activeElement?.id', 'password')
        // The link keeps its place in the tab order, one stop further on — it
        // must stay reachable, not be removed with tabindex="-1".
        ->keys('#password', ['Tab'])
        ->assertScript(
            "document.activeElement?.getAttribute('href')",
            '/forgot-password',
        );

    // ...and it is still drawn above the field, flush with its right edge,
    // proving the DOM reorder was undone visually rather than relocating it.
    $page->assertScript(<<<'JS'
    (() => {
        const link = document.querySelector('a[href="/forgot-password"]').getBoundingClientRect();
        const input = document.getElementById('password').getBoundingClientRect();

        return link.bottom <= input.top && Math.abs(link.right - input.right) <= 1;
    })()
    JS, true);
});

test('the register screen scores a password as it is typed', function (): void {
    // The meter is advisory, so it must actually report a score rather than
    // sitting silently at zero — the failure mode when the estimator throws.
    visit('/register')
        ->type('#password', 'correct horse battery staple')
        ->assertPresent('@password-strength')
        ->assertSee('Very strong');
});

test('an invitation dresses the register panel and locks the invited address', function (): void {
    $owner = User::factory()->create(['name' => 'Jonas Weber']);
    $team = Team::factory()->create(['name' => 'Acme Co']);
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'maya@acme.co',
        'invited_by' => $owner->id,
    ]);

    visit('/register?invitation='.$invitation->code)
        ->assertPresent('@invitation-panel')
        ->assertSee('Jonas Weber invited you')
        ->assertSee('Acme Co')
        // The address is settled, so the field states it rather than asking.
        ->assertValue('@invited-email', 'maya@acme.co')
        // A boolean attribute, so Vue renders it valueless.
        ->assertAttribute('@invited-email', 'readonly', '');
});

test('registering under an invitation creates the account on the invited address', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'maya@acme.co',
        'invited_by' => $owner->id,
    ]);

    visit('/register?invitation='.$invitation->code)
        ->type('#name', 'Maya Chen')
        ->type('#password', 'a-long-enough-password')
        ->type('#password_confirmation', 'a-long-enough-password')
        ->click('@register-user-button')
        ->assertPathIsNot('/register');

    expect(User::where('email', 'maya@acme.co')->exists())->toBeTrue();
});

test('the auth shell has no serious accessibility violations in either theme', function (): void {
    $page = visit('/login')->assertNoAccessibilityIssues();

    // Persist 'dark' before applying the class, or `useAppearance` re-resolves
    // 'system' back to light and reverts the toggle mid-audit.
    $page->script(<<<'JS'
    () => {
        localStorage.setItem('appearance', 'dark');
        document.documentElement.classList.add('dark');
        document.documentElement.style.colorScheme = 'dark';
    }
    JS);

    $page->wait(0.5)->assertNoAccessibilityIssues();
});

test('the register screen has no serious accessibility violations', function (): void {
    visit('/register')->assertNoAccessibilityIssues();
});
