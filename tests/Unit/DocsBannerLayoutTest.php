<?php

declare(strict_types=1);

/**
 * Starlight renders the release banner inside `<main>`, between the two
 * sidebars, so a site-wide notice reads as a callout bolted onto the article —
 * and the sidebar's active-page pill paints over its left edge. The fix hoists
 * the banner out of `<main>` and into the page shell, then offsets every piece
 * of chrome Starlight pins to the viewport by however much of the banner is
 * still on screen. Each half is inert on its own: hoisting without the offsets
 * puts the banner *under* the fixed header, and offsets without the hoist move
 * chrome away from a banner that never left the content column. These tests
 * keep both halves, and the sidebar label that was wrapping beside them,
 * from drifting apart. See #885.
 */
$docsPath = fn (string $file): string => dirname(__DIR__, 2).'/docs/'.$file;

$docsFile = fn (string $file): string => (string) file_get_contents(dirname(__DIR__, 2).'/docs/'.$file);

test('starlight is told to use the banner and page frame overrides', function (string $component) use ($docsPath, $docsFile): void {
    expect($docsFile('astro.config.mjs'))->toContain($component.": './src/components/".$component.".astro'")
        ->and($docsPath('src/components/'.$component.'.astro'))->toBeFile();
})->with(['Banner', 'PageFrame']);

/**
 * The override has to render *nothing*: Starlight calls it from inside `<main>`,
 * which is the position being moved away from. Anything left in its template
 * would put a second banner back where the first one was.
 */
test('the banner slot starlight calls from inside main renders nothing', function () use ($docsFile): void {
    $template = preg_replace('/^---.*?^---/ms', '', $docsFile('src/components/Banner.astro'));

    expect(trim((string) $template))->toBe('');
});

test('the page frame renders the real banner above the header and outside the content column', function () use ($docsFile): void {
    $frame = $docsFile('src/components/PageFrame.astro');

    // Importing Starlight's own component rather than re-implementing it keeps
    // the banner's markup, styling and `banner` frontmatter handling intact —
    // and sidesteps the override indirection, which now resolves to the blank
    // component above.
    expect($frame)->toContain("import Banner from '@astrojs/starlight/components/Banner.astro';");

    $banner = strpos($frame, '<Banner />');
    $header = strpos($frame, '<header class="header">');
    $mainFrame = strpos($frame, '<div class="main-frame">');

    expect($banner)->toBeInt()->toBeLessThan($header)
        ->and($banner)->toBeLessThan($mainFrame);
});

/**
 * One published custom property drives every offset, so the chrome tracks the
 * banner as it scrolls away instead of reserving a fixed gap forever.
 */
test('the page frame publishes how much of the banner is still on screen', function () use ($docsFile): void {
    $frame = $docsFile('src/components/PageFrame.astro');

    expect($frame)->toContain("setProperty('--td-banner-height'")
        // Without a scroll listener the offset freezes at the banner's full
        // height and the header never reaches the top of the viewport; without
        // the observer it never survives a reflow (font swap, viewport rotation)
        // that rewraps the banner text.
        ->and($frame)->toContain("addEventListener('scroll'")
        ->and($frame)->toContain('ResizeObserver');
});

test('the offset falls back to zero so pages without a banner keep starlight layout', function () use ($docsFile): void {
    expect($docsFile('src/styles/custom.css'))->toMatch('/--td-banner-height:\s*0px;/');
});

/**
 * Every element Starlight pins to the viewport, paired with the file that has
 * to shift it down. Miss one and it is the piece that paints over the banner.
 *
 * @return list<array{string, string}>
 */
$pinnedChrome = [
    // The fixed top bar, moved below the banner rather than over it.
    ['src/components/PageFrame.astro', '.header'],
    // The desktop sidebar pane, whose active-page pill was the visible overlap.
    ['src/components/PageFrame.astro', '.sidebar-pane'],
    ['src/styles/custom.css', '.right-sidebar'],
    ['src/styles/custom.css', 'mobile-starlight-toc nav'],
    ['src/styles/custom.css', 'starlight-menu-button button'],
];

test('every viewport-pinned element is offset by the visible part of the banner', function (string $file, string $selector) use ($docsFile): void {
    $rule = [];
    preg_match('/'.preg_quote($selector, '/').'\s*\{(?<body>[^}]*)\}/', $docsFile($file), $rule);

    expect($rule['body'] ?? '')->toContain('--td-banner-height');
})->with($pinnedChrome);

/**
 * Anchor jumps land under the header otherwise: the header sits a banner's
 * height lower while any of the banner is still on screen.
 */
test('anchor scroll padding accounts for the banner too', function () use ($docsFile): void {
    $css = $docsFile('src/styles/custom.css');

    preg_match_all('/scroll-padding-top:(?<value>[^;]*);/', $css, $matches);

    expect($matches['value'])->not->toBeEmpty()
        ->each->toContain('--td-banner-height');
});

/**
 * The sidebar has no width to spare, so the bound is the longest entry that is
 * already known to render on one line — every other leaf in the tree. Judging
 * the comparison entry against its own neighbours keeps the check honest as the
 * sidebar grows, instead of freezing a magic number.
 */
test('the comparison sidebar entry is no longer than the entries that already fit on one line', function () use ($docsFile): void {
    preg_match_all("/\{ label: '(?<label>[^']*)', (?:slug|link): '(?<target>[^']*)' \}/", $docsFile('astro.config.mjs'), $entries, PREG_SET_ORDER);

    $labels = collect($entries)->pluck('label', 'target');
    $comparison = $labels->get('comparison', '');

    // Guard the guard: a changed entry shape would empty the scan and leave
    // this comparing one label against nothing.
    expect($labels->count())->toBeGreaterThan(10)
        ->and($comparison)->not->toBeEmpty()
        ->and(strlen($comparison))->toBeLessThanOrEqual(
            $labels->forget('comparison')->map(fn (string $label): int => strlen($label))->max()
        );
});

/**
 * Shortening the sidebar label must not reach the page itself: the long title
 * and its description are what rank the page for "self-hosted Slack
 * alternative", which is the whole reason the page exists.
 */
test('the comparison page keeps the long title and description the sidebar no longer repeats', function () use ($docsFile): void {
    $page = $docsFile('src/content/docs/comparison.md');

    expect($page)->toContain('title: The Desk vs Slack, Mattermost & Rocket.Chat')
        ->and($page)->toContain('self-hosted Slack alternative');
});
