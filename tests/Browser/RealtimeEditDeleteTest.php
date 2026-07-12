<?php

declare(strict_types=1);

test('an edited message updates live for another member', function (): void {
    ['owner' => $alice, 'member' => $bob] = browserTeamWithChannel();

    $alicePage = signInThroughBrowser($alice);
    $bobPage = signInThroughBrowser($bob);

    $bobPage->assertPresent('@message-composer-input');

    $original = 'First draft of the note';
    $edited = 'Second draft of the note';

    $alicePage
        ->type('@message-composer-input', $original)
        ->click('@message-composer-send')
        ->assertSee($original);

    $bobPage->assertSee($original);

    // Reveal the hover action bar on Alice's own row, then open the inline editor.
    $alicePage
        ->hover('@message-body')
        ->click('@message-edit')
        ->clear('@message-edit-input')
        ->type('@message-edit-input', $edited)
        ->keys('@message-edit-input', ['Enter'])
        ->assertSee($edited)
        ->assertPresent('@message-edited');

    // Bob receives the edited body and the "edited" marker over Reverb.
    $bobPage->assertSee($edited);
    $bobPage->assertPresent('@message-edited');
});

test('a deleted message becomes a tombstone live for another member', function (): void {
    ['owner' => $alice, 'member' => $bob] = browserTeamWithChannel();

    $alicePage = signInThroughBrowser($alice);
    $bobPage = signInThroughBrowser($bob);

    $bobPage->assertPresent('@message-composer-input');

    $body = 'A message soon to be deleted';

    $alicePage
        ->type('@message-composer-input', $body)
        ->click('@message-composer-send')
        ->assertSee($body);

    $bobPage->assertSee($body);

    // Reveal the hover action bar, delete, and confirm in the dialog.
    $alicePage
        ->hover('@message-body')
        ->click('@message-delete')
        ->click('@delete-message-confirm')
        ->assertPresent('@message-tombstone');

    // Bob's copy collapses to the tombstone over Reverb.
    $bobPage->assertPresent('@message-tombstone');
    $bobPage->assertDontSee($body);
});
