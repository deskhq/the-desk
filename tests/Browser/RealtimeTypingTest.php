<?php

declare(strict_types=1);

test('a member sees the typing indicator while another composes', function (): void {
    ['owner' => $alice, 'member' => $bob] = browserTeamWithChannel();

    $alicePage = signInThroughBrowser($alice);
    $bobPage = signInThroughBrowser($bob);

    $bobPage->assertPresent('@message-composer-input');

    // Keystroke by keystroke rather than a single fill: the first input signals
    // immediately (leading-edge throttle) and typing past the 2.5s throttle
    // window re-signals, so Bob still catches a beat even if his channel
    // subscription was still authorizing when the first one broadcast.
    $alicePage->typeSlowly('@message-composer-input', 'Drafting a thought…', 150);

    $bobPage->assertSeeIn('@typing-indicator', $alice->name);
});
