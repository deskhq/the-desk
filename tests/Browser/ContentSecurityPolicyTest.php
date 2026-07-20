<?php

declare(strict_types=1);

/**
 * The policy is enforcing by default, but .env.example turns report-only on for
 * local development (and CI boots the browser suite from it), so state the
 * posture under test: violations must block here, not just warn.
 */
beforeEach(function (): void {
    config([
        'csp.enabled' => true,
        'csp.report_only' => false,
    ]);
});

test('the app runs under an enforcing content security policy', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    // Reaching the composer at all means the entry bundle and the channel page's
    // own chunk both executed — that is the nonce and 'strict-dynamic' working.
    $page = signInThroughBrowser($alice)
        ->assertPresent('@message-composer-input');

    $page->script(<<<'JS'
        () => {
            window.__cspViolations = [];
            document.addEventListener('securitypolicyviolation', (event) => {
                window.__cspViolations.push(event.violatedDirective + ' ' + event.blockedURI);
            });
        }
        JS);

    // A client-side Inertia visit to a page whose chunk has not been loaded yet:
    // the import() the browser makes carries no nonce of its own and only runs
    // because 'strict-dynamic' extends trust to it. This is the exact path a
    // nonce-only script-src would break.
    $violations = $page
        ->click('@sidebar-menu-button')
        ->assertPresent('@settings-menu-item')
        // Let the dropdown settle past its open/pointer-grace window, otherwise
        // the item click can be swallowed and never navigate.
        ->wait(0.5)
        ->click('@settings-menu-item')
        ->assertPathContains('/settings')
        ->assertPresent('[data-test="settings-nav-profile"]')
        ->script('() => window.__cspViolations');

    expect($violations)->toBe([]);
});
