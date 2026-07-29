<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;

/**
 * The one-time passkey offer a newly registered user gets on their first landing
 * in the workspace (#892).
 *
 * These tests deliberately never attempt a real WebAuthn ceremony:
 * `pest-plugin-browser` exposes no CDP virtual authenticator, so no credential
 * can be produced from the browser suite — the same limitation
 * `tests/Browser/PasskeySettingsTest.php` already works around by asserting only
 * that the affordance is present. What is covered here is everything around the
 * ceremony: that the prompt appears at all, that it goes ahead of the first-run
 * tour, that answering it starts that tour, and that it presents as a bottom
 * sheet on a phone. The success path stays manually verified, as it is today.
 */

/**
 * Register a brand-new account through the real form and land in the workspace.
 * Registration is what queues the prompt, so it cannot be shortcut with a factory
 * user: the trigger is a session key the registering request writes.
 */
function registerThroughBrowser(int $width = 1280, int $height = 800): AwaitableWebpage
{
    return visit('/register')
        ->resize($width, $height)
        ->type('#name', 'Nadia Fresh')
        ->type('#email', 'nadia@example.com')
        ->type('#password', 'password')
        ->type('#password_confirmation', 'password')
        ->click('@register-user-button')
        ->assertPathIsNot('/register');
}

test('a freshly registered user is offered a passkey, ahead of the first-run tour', function (): void {
    config(['fortify.passkeys_enabled' => true]);

    registerThroughBrowser()
        ->assertPresent('@prompt-dialog')
        ->assertSee('One more thing: skip the password next time')
        // Prefilled from the device the account was created on, and editable
        // because a passkey cannot be renamed afterwards.
        ->assertPresent('@passkey-prompt-name')
        ->assertSee("You can't rename it later, so pick something you'll recognise.")
        // The tour waits its turn: three coachmarks followed by a modal is the
        // worse order, so the prompt owns the first paint.
        ->assertNotPresent('@onboarding-tour');
});

test('dismissing the prompt closes it and starts the onboarding tour', function (): void {
    config(['fortify.passkeys_enabled' => true]);

    registerThroughBrowser()
        ->assertPresent('@prompt-dialog')
        ->click('@prompt-secondary')
        ->assertNotPresent('@prompt-dialog')
        ->assertPresent('@onboarding-tour');
    // That the answer also clears the session key — so a reload never re-asks —
    // is covered server-side in tests/Feature/Auth/PostRegistrationPromptTest.php,
    // where the session can be read directly.
});

test('the prompt presents as a bottom sheet on a phone', function (): void {
    config(['fortify.passkeys_enabled' => true]);

    registerThroughBrowser(390, 844)
        ->assertPresent('@prompt-dialog')
        // The shorter copy the sheet is drawn with, and the sheet's own chrome.
        ->assertSee('Skip the password next time')
        ->assertPresent('@sheet-grab-handle')
        ->assertScript(<<<'JS'
        (() => {
            const sheet = document.querySelector('[data-slot="dialog-content"]');

            if (sheet === null) {
                return false;
            }

            const box = sheet.getBoundingClientRect();

            // Pinned to the bottom edge, full width, and leaving the workspace
            // visible above the scrim so it reads as a step rather than a wall.
            return Math.round(box.bottom) === window.innerHeight
                && Math.round(box.width) === window.innerWidth
                && box.top > 0;
        })()
        JS, true)
        // Both controls stack full-width at touch height: the way out is never a
        // whisper next to the primary action.
        ->assertScript(<<<'JS'
        (() => ['prompt-primary', 'prompt-secondary'].every((test) => {
            const box = document.querySelector(`[data-test="${test}"]`).getBoundingClientRect();

            return box.height >= 44 && box.width >= window.innerWidth - 60;
        }))()
        JS, true);
});

test('no prompt is offered when passkeys are switched off', function (): void {
    config(['fortify.passkeys_enabled' => false]);

    registerThroughBrowser()
        ->assertNotPresent('@prompt-dialog')
        // With nothing in the way, the first-run tour starts as it always did.
        ->assertPresent('@onboarding-tour');
});

test('the prompt has no serious accessibility violations', function (): void {
    config(['fortify.passkeys_enabled' => true]);

    // The automated gate does not audit a11y, and this dialog is new chrome: an
    // icon tile, a serif title, a field and two pill actions. Settle first — the
    // dialog fades in over 200ms, and axe reads a half-transparent title as a
    // contrast failure no one ever sees.
    registerThroughBrowser()
        ->assertPresent('@prompt-dialog')
        ->wait(0.5)
        ->assertNoAccessibilityIssues();
});
