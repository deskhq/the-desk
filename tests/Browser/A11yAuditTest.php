<?php

declare(strict_types=1);

test('the authenticated channel page has no critical accessibility violations', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    // Runs axe-core (bundled with the browser plugin) against the real rendered
    // DOM — the guard rail this a11y effort stands up. Level 0 is CRITICAL only:
    // the shell still trips SERIOUS color-contrast on `--muted-foreground`
    // (tracked in #269). Once that slice lands, raise this to the default SERIOUS
    // level (1) by dropping the argument.
    signInThroughBrowser($alice)
        ->assertNoAccessibilityIssues(0);
});
