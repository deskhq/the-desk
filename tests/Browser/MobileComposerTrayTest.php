<?php

declare(strict_types=1);

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Pest\Browser\Api\AwaitableWebpage;

/**
 * The composer's tray and banner controls below `md` (#920).
 *
 * #889 raised the control row and the recording strip to the 44pt touch
 * minimum; everything the composer stacks around the input was left at its
 * desktop size. The attachment chips were worse than small: their remove button
 * was revealed by `group-hover`, a state a touch screen cannot enter, so a
 * staged file could not be dropped on a phone at all.
 *
 * Below `md` each chip therefore carries an always-visible remove control with a
 * 44x44 hit box. The three chip shapes cannot take the same one: the file and
 * failed chips are roomy rows whose existing right-hand button simply grows, the
 * audio chip gives its button a column of its own (a 44px overlay would sit on
 * the scrubber's right end), and the image chip keeps a small corner badge whose
 * hit box reaches back into the thumbnail — the thumbnail has no competing tap
 * target, and a 44pt *visible* button would bury the picture it belongs to.
 *
 * From `md` up every one of them is what it was: hover-revealed, desktop-sized.
 */

/**
 * A JS expression building an image `File` the browser can preview and the
 * server can thumbnail — painted on a canvas so the bytes are a genuine PNG.
 */
const IMAGE_FILE = <<<'JS'
(async () => {
    const canvas = Object.assign(document.createElement('canvas'), { width: 1200, height: 800 });
    const context = canvas.getContext('2d');
    context.fillStyle = '#8a6a3b';
    context.fillRect(0, 0, canvas.width, canvas.height);
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));

    return new File([blob], 'venue.png', { type: 'image/png' });
})()
JS;

/** A JS expression building a short, genuinely decodable 8kHz mono WAV. */
const AUDIO_FILE = <<<'JS'
(() => {
    const samples = 800;
    const view = new DataView(new ArrayBuffer(44 + samples));
    const ascii = (offset, text) => [...text].forEach((c, i) => view.setUint8(offset + i, c.charCodeAt(0)));

    ascii(0, 'RIFF');
    view.setUint32(4, 36 + samples, true);
    ascii(8, 'WAVEfmt ');
    view.setUint32(16, 16, true);
    view.setUint16(20, 1, true);
    view.setUint16(22, 1, true);
    view.setUint32(24, 8000, true);
    view.setUint32(28, 8000, true);
    view.setUint16(32, 1, true);
    view.setUint16(34, 8, true);
    ascii(36, 'data');
    view.setUint32(40, samples, true);

    for (let i = 0; i < samples; i++) {
        view.setUint8(44 + i, 128 + Math.round(120 * Math.sin(i / 6)));
    }

    return new File([view.buffer], 'standup.wav', { type: 'audio/wav' });
})()
JS;

/** A JS expression building a plain non-image, non-audio document. */
const DOCUMENT_FILE = <<<'JS'
new File(['%PDF-1.4 offsite quote'], 'quote.pdf', { type: 'application/pdf' })
JS;

/**
 * Stage a file through the composer's real file input and settle its chip.
 *
 * The file is handed to the input through a `DataTransfer` rather than
 * Playwright's `setInputFiles`, which only offers the driver a local path and is
 * refused outright by the shared Playwright server the plugin connects to. What
 * follows is the real thing either way: the composer's own change handler runs,
 * and the pre-upload is a genuine `multipart/form-data` round trip to the
 * in-process server — so these chips are the ones a user gets rather than
 * fixtures painted into the DOM.
 */
function stageAttachment(AwaitableWebpage $page, string $fileExpression, int $expectedChips, string $status = 'done'): AwaitableWebpage
{
    return $page->assertScript(<<<JS
    (async () => {
        const transfer = new DataTransfer();
        transfer.items.add(await ({$fileExpression}));

        const input = document.querySelector('[data-test=composer-file-input]');
        input.files = transfer.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));

        const settled = () => document.querySelectorAll(
            '[data-test=composer-attachment][data-status={$status}]',
        ).length === {$expectedChips};

        for (let frame = 0; frame < 600 && ! settled(); frame++) {
            await new Promise(requestAnimationFrame);
        }

        return settled();
    })()
    JS, true);
}

/**
 * The shared #general with a rooted thread, so the thread panel — the one
 * composer that offers the send-to-channel control — has something to open.
 *
 * @return array{owner: User, channel: Channel, root: Message}
 */
function trayThreadChannel(): array
{
    ['owner' => $alice, 'member' => $bob, 'channel' => $channel] = browserTeamWithChannel();

    $root = Message::factory()->for($channel)->for($alice)->create([
        'body' => 'Thread root for the tray suite',
        'created_at' => now()->subMinutes(20),
    ]);

    Message::factory()->for($channel)->inThread($root)->create([
        'user_id' => $bob->id,
        'body' => 'A reply so the summary renders',
        'created_at' => now()->subMinutes(19),
    ]);

    $root->forceFill(['reply_count' => 1, 'last_reply_at' => now()->subMinutes(19)])->save();

    return ['owner' => $alice, 'channel' => $channel, 'root' => $root];
}

/**
 * Whether a control really answers a tap across its whole measured box, defined
 * once and spliced into the measuring scripts below.
 *
 * The probes are the box's four edge midpoints and its centre rather than its
 * corners: a rounded control does not hit-test its own corners (a `rounded-full`
 * 44px button is a circle, and its corner pixels belong to whatever is behind
 * it), so corners would report every one of these as covered. The midpoints
 * still span the full width and height, which is the claim worth making.
 */
const REACHES_ACROSS_ITS_BOX = <<<'JS'
(element, box) => [
    [box.left + box.width / 2, box.top + box.height / 2],
    [box.left + 1, box.top + box.height / 2],
    [box.right - 1, box.top + box.height / 2],
    [box.left + box.width / 2, box.top + 1],
    [box.left + box.width / 2, box.bottom - 1],
].every(([x, y]) => element.contains(document.elementFromPoint(x, y)))
JS;

/**
 * Measure every remove control in the tray, keyed by the chip kind it belongs
 * to: its hit box, whether it is visible without a hover, and whether it is
 * really the topmost element across that box.
 */
function measureRemoveTargets(): string
{
    $reaches = REACHES_ACROSS_ITS_BOX;

    return <<<JS
    (() => {
        const reaches = {$reaches};

        return Object.fromEntries([...document.querySelectorAll('[data-test=composer-attachment]')].map((chip) => {
            const button = chip.querySelector('[data-test=composer-attachment-remove]');
            const box = button.getBoundingClientRect();

            return [chip.dataset.kind, {
                width: Math.round(box.width),
                height: Math.round(box.height),
                opacity: getComputedStyle(button).opacity,
                hittable: reaches(button, box),
            }];
        }));
    })()
    JS;
}

/**
 * A script asserting one banner's dismiss button clears 44x44 and is topmost
 * across that box.
 */
function dismissClearsTouchMinimum(string $test): string
{
    $reaches = REACHES_ACROSS_ITS_BOX;

    return <<<JS
    (() => {
        const button = document.querySelector('[data-test={$test}]');
        const box = button.getBoundingClientRect();

        return box.width >= 44 && box.height >= 44 && ({$reaches})(button, box);
    })()
    JS;
}

test('below md a staged image is removed by touch with no hover in the way', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $page = signInThroughBrowser($alice)
        ->resize(390, 844)
        ->assertPresent('@message-composer-input');

    $page = stageAttachment($page, IMAGE_FILE, 1);

    // Nothing has been hovered — the mouse has not moved since the page opened —
    // and the control is already at full opacity and hit-testable end to end.
    $measurements = $page->script(measureRemoveTargets());

    expect($measurements['image']['opacity'])->toBe('1')
        ->and($measurements['image']['width'])->toBeGreaterThanOrEqual(44)
        ->and($measurements['image']['height'])->toBeGreaterThanOrEqual(44)
        ->and($measurements['image']['hittable'])->toBeTrue();

    // What the user sees stays a badge: the thumbnail grows on a phone and the
    // painted disc takes a corner of it, not the picture.
    $page->assertScript(<<<'JS'
    (() => {
        const chip = document.querySelector('[data-test=composer-attachment][data-kind=image]');
        const thumb = chip.getBoundingClientRect();
        const badge = chip.querySelector('[data-test=composer-attachment-remove-badge]').getBoundingClientRect();

        return thumb.width >= 96
            && badge.width <= 28
            && badge.width * badge.height <= thumb.width * thumb.height / 8;
    })()
    JS, true);

    $page->click('@composer-attachment-remove')
        ->assertNotPresent('@composer-attachment');
});

test('below md every tray chip carries a 44pt remove target', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $page = signInThroughBrowser($alice)
        ->resize(390, 844)
        ->assertPresent('@message-composer-input');

    $page = stageAttachment($page, IMAGE_FILE, 1);
    $page = stageAttachment($page, AUDIO_FILE, 2);
    $page = stageAttachment($page, DOCUMENT_FILE, 3);

    $measurements = $page->script(measureRemoveTargets());

    expect(array_keys($measurements))->toEqualCanonicalizing(['image', 'audio', 'file']);

    foreach ($measurements as $kind => $target) {
        expect($target['opacity'])->toBe('1', "the {$kind} chip's remove button is hover-revealed")
            ->and($target['width'])->toBeGreaterThanOrEqual(44, "the {$kind} chip's remove button is too narrow")
            ->and($target['height'])->toBeGreaterThanOrEqual(44, "the {$kind} chip's remove button is too short")
            ->and($target['hittable'])->toBeTrue("the {$kind} chip's remove button is covered");
    }

    // The audio chip's button took a column of its own rather than an overlay,
    // so the scrubber it used to sit on is clear of it.
    $page->assertScript(<<<'JS'
    (() => {
        const chip = document.querySelector('[data-test=composer-attachment][data-kind=audio]');
        const button = chip.querySelector('[data-test=composer-attachment-remove]').getBoundingClientRect();
        const scrubber = chip.querySelector('[data-test=audio-player-scrubber]').getBoundingClientRect();

        return button.left >= scrubber.right;
    })()
    JS, true);

    // The tray still fits the phone: nothing runs off its right edge.
    $page->assertScript(<<<'JS'
    (() => {
        const tray = document.querySelector('[data-test=composer-attachment-tray]').getBoundingClientRect();

        return [...document.querySelectorAll('[data-test=composer-attachment]')]
            .every((chip) => chip.getBoundingClientRect().right <= tray.right + 1);
    })()
    JS, true);
});

test('below md the staged tray passes the axe audit in either theme', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $page = signInThroughBrowser($alice)
        ->resize(390, 844)
        ->assertPresent('@message-composer-input');

    $page = stageAttachment($page, IMAGE_FILE, 1);
    $page = stageAttachment($page, AUDIO_FILE, 2);
    $page = stageAttachment($page, DOCUMENT_FILE, 3);

    // The badge that is now painted on every chip is new contrast on the phone:
    // desktop only ever showed it under a hover, where no audit reached it.
    $page->assertNoAccessibilityIssues();

    switchToDarkTheme($page)->assertNoAccessibilityIssues();
});

test('below md an upload that failed can still be dropped at 44pt', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $page = signInThroughBrowser($alice)
        ->resize(390, 844)
        ->assertPresent('@message-composer-input');

    // Send the pre-upload somewhere unreachable so it fails as a transport
    // error rather than a validation rejection: a 422 is definitive and drops
    // the row, while this leaves the retryable failed chip the test is after.
    $page->script(<<<'JS'
    (() => {
        const open = XMLHttpRequest.prototype.open;

        XMLHttpRequest.prototype.open = function (method, url, ...rest) {
            const target = String(url).includes('/attachments') ? 'http://127.0.0.1:1/dropped' : url;

            return open.call(this, method, target, ...rest);
        };
    })()
    JS);

    $page = stageAttachment($page, DOCUMENT_FILE, 1, 'failed');

    $measurements = $page->script(measureRemoveTargets());

    expect($measurements['failed']['width'])->toBeGreaterThanOrEqual(44)
        ->and($measurements['failed']['height'])->toBeGreaterThanOrEqual(44)
        ->and($measurements['failed']['hittable'])->toBeTrue();

    $page->click('@composer-attachment-remove')
        ->assertNotPresent('@composer-attachment');
});

test('below md the reply and edit banners dismiss at 44pt', function (): void {
    ['owner' => $alice, 'member' => $bob, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    $message = Message::factory()->for($channel)->for($alice)->create([
        'body' => 'Roomy rows can just grow.',
    ]);

    $page = signInThroughBrowser($bob)
        ->resize(390, 844)
        ->navigate(browserChannelUrl($team, $channel))
        ->assertPresent("#message-{$message->id}");

    longPressMessage($page, $message->id)
        ->click('@sheet-reply')
        ->assertPresent('@reply-preview')
        // The sheet that set the reply target is still painted over the composer
        // for the length of its close, and it would answer the hit test below.
        ->assertNotPresent('@message-actions-sheet')
        ->assertScript(dismissClearsTouchMinimum('reply-preview-dismiss'), true);

    $page->click('@reply-preview-dismiss')
        ->assertNotPresent('@reply-preview');

    // The editing banner is the same row shape, so it takes the same target.
    $page
        ->type('@message-composer-input', 'Draft to correct')
        ->click('@message-composer-send')
        ->assertSee('Draft to correct')
        ->click('@message-composer-input')
        ->keys('@message-composer-input', ['ArrowUp'])
        ->assertPresent('@composer-editing-banner')
        ->assertScript(dismissClearsTouchMinimum('composer-editing-dismiss'), true);
});

test('below md the send-to-channel row is toggled anywhere along it', function (): void {
    ['owner' => $alice] = trayThreadChannel();

    $page = signInThroughBrowser($alice)
        ->resize(390, 844)
        ->assertSee('Thread root for the tray suite');

    $page->click('[data-test=thread-summary]')
        ->assertPresent('@send-to-channel-row');

    // The whole row is the target: full width, 44pt tall, and topmost at its far
    // end, where nothing but the label's own padding reaches.
    $page->assertScript(<<<'JS'
    (() => {
        const row = document.querySelector('[data-test=send-to-channel-row]');
        const box = row.getBoundingClientRect();
        const parent = row.parentElement.getBoundingClientRect();
        const farEnd = document.elementFromPoint(box.right - 2, box.top + box.height / 2);

        return box.height >= 44 && box.width >= parent.width - 1 && row.contains(farEnd);
    })()
    JS, true);

    // The box itself is left alone: the row grew, the checkbox did not.
    $page->assertScript(<<<'JS'
    (() => {
        const box = document.querySelector('[data-test=send-to-channel]').getBoundingClientRect();

        return Math.round(box.width) === 14 && Math.round(box.height) === 14;
    })()
    JS, true);

    $page->click('@send-to-channel-row')
        ->assertChecked('@send-to-channel');
});

test('from md up the tray keeps its hover-revealed desktop buttons', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $page = signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->assertPresent('@message-composer-input');

    $page = stageAttachment($page, IMAGE_FILE, 1);

    // Untouched desktop behaviour: a 76px thumbnail whose remove badge stays
    // invisible until the chip is hovered.
    $page->assertScript(<<<'JS'
    (() => {
        const chip = document.querySelector('[data-test=composer-attachment][data-kind=image]');
        const button = chip.querySelector('[data-test=composer-attachment-remove]');

        return Math.round(chip.getBoundingClientRect().width) === 76
            && getComputedStyle(button).opacity === '0';
    })()
    JS, true);

    $page->hover('@composer-attachment')
        ->assertScript(<<<'JS'
        (() => getComputedStyle(
            document.querySelector('[data-test=composer-attachment-remove]'),
        ).opacity === '1')()
        JS, true);
});
