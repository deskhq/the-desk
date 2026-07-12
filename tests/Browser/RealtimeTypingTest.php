<?php

declare(strict_types=1);

test('a member sees the typing indicator while another composes', function (): void {
    ['owner' => $alice, 'member' => $bob] = browserTeamWithChannel();

    $alicePage = signInThroughBrowser($alice);
    $bobPage = signInThroughBrowser($bob);

    $bobPage->assertPresent('@message-composer-input');

    // The first keystroke whispers immediately (leading-edge throttle), so Bob's
    // roster picks Alice up over Reverb without her ever sending a message.
    $alicePage->type('@message-composer-input', 'Drafting a thought…');

    $bobPage->assertSeeIn('@typing-indicator', $alice->name);
});
