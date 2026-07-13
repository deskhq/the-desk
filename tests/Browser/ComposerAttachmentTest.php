<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

/**
 * Drive the composer's attachment tray end to end in a real browser: stage a
 * file through the picker's hidden input, watch it appear in the tray, then send
 * the message and confirm the tray clears.
 *
 * The file is injected via a `DataTransfer` in the page (rather than Playwright's
 * `attach`, which rejects local paths against the in-process server) so the real
 * `change` → pre-upload → claim-on-send path runs exactly as it does for a user.
 * The send button only enables once the upload finishes, so the click's
 * actionability wait proves the upload succeeded; the server-side claim itself is
 * covered by the foundation's feature tests. DB state isn't asserted here — the
 * in-process browser server writes on a connection the test process can't see.
 */
test('a picked file uploads to the composer tray and clears on send', function (): void {
    Storage::fake('local');
    config(['attachments.disk' => 'local']);

    ['owner' => $alice] = browserTeamWithChannel();

    $page = signInThroughBrowser($alice);
    $page->assertPresent('@message-composer-input');

    // Stage a file exactly as the native picker would: set the hidden input's
    // files through a DataTransfer, then fire the change the composer listens for.
    $page->script(<<<'JS'
        const input = document.querySelector('[data-test="composer-file-input"]');
        const data = new DataTransfer();
        data.items.add(new File(['launch checklist contents'], 'launch-checklist.txt', { type: 'text/plain' }));
        input.files = data.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    JS);

    // The file stages as a tray chip and pre-uploads immediately.
    $page
        ->assertPresent('@composer-attachment')
        ->assertSee('launch-checklist.txt');

    // Sending posts the message with the claimed attachment. The send button is
    // disabled until the upload completes, so this click waits out the in-flight
    // upload — a failed upload would leave it disabled and time out here.
    $page
        ->type('@message-composer-input', 'Here you go')
        ->click('@message-composer-send')
        ->assertSee('Here you go')
        ->assertValue('@message-composer-input', '')
        ->assertMissing('@composer-attachment');
});
