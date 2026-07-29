<?php

declare(strict_types=1);

test('the public marketing page has no serious accessibility violations in either theme', function (): void {
    // The page root fades in over `duration-700` (`starting:opacity-0`). Auditing
    // before it settles reads every colour composited over the backdrop mid-fade,
    // manufacturing contrast failures — let the entrance finish first so axe sees
    // the opaque, shipped colours.
    $page = visit('/')
        ->assertSee('The Desk')
        ->wait(0.9)
        ->assertNoAccessibilityIssues();

    switchToDarkTheme($page)
        ->assertNoAccessibilityIssues();
});
