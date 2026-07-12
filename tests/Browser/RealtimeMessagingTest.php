<?php

declare(strict_types=1);

test('a message sent by one member appears live for another', function (): void {
    ['owner' => $alice, 'member' => $bob] = browserTeamWithChannel();

    $alicePage = signInThroughBrowser($alice);
    $bobPage = signInThroughBrowser($bob);

    $bobPage->assertPresent('@message-composer-input');

    $body = 'Live hello from Alice';

    $alicePage
        ->type('@message-composer-input', $body)
        ->click('@message-composer-send')
        ->assertSee($body);

    // Bob receives it over Reverb without reloading.
    $bobPage->assertSee($body);
});
