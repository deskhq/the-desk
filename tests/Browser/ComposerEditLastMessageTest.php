<?php

declare(strict_types=1);

test('pressing up in the empty composer edits and saves the last message', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $page = signInThroughBrowser($alice);
    $page->assertPresent('@message-composer-input');

    $original = 'Draft with a typ0';
    $edited = 'Draft with the typo fixed';

    $page
        ->type('@message-composer-input', $original)
        ->click('@message-composer-send')
        ->assertSee($original);

    // ArrowUp on the (now empty) composer recalls the last message verbatim into
    // an edit mode with its brass banner affordance.
    $page
        ->click('@message-composer-input')
        ->keys('@message-composer-input', ['ArrowUp'])
        ->assertPresent('@composer-editing-banner')
        ->assertValue('@message-composer-input', $original);

    // Enter saves the correction through the same edit/PATCH path the inline
    // editor uses; the composer resets and the row shows the edited marker.
    $page
        ->clear('@message-composer-input')
        ->type('@message-composer-input', $edited)
        ->keys('@message-composer-input', ['Enter'])
        ->assertSee($edited)
        ->assertPresent('@message-edited')
        ->assertMissing('@composer-editing-banner')
        ->assertValue('@message-composer-input', '');
});

test('escape cancels a composer edit without changing the message', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $page = signInThroughBrowser($alice);
    $page->assertPresent('@message-composer-input');

    $original = 'Nothing to change here';

    $page
        ->type('@message-composer-input', $original)
        ->click('@message-composer-send')
        ->assertSee($original);

    // Enter edit mode, then Esc restores the empty composer with no PATCH.
    $page
        ->click('@message-composer-input')
        ->keys('@message-composer-input', ['ArrowUp'])
        ->assertPresent('@composer-editing-banner')
        ->keys('@message-composer-input', ['Escape'])
        ->assertMissing('@composer-editing-banner')
        ->assertValue('@message-composer-input', '')
        ->assertSee($original)
        ->assertMissing('@message-edited');
});
