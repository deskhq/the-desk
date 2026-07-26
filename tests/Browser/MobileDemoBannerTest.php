<?php

declare(strict_types=1);

/**
 * The demo strip at phone widths (#841).
 *
 * The banner is `position: fixed`, so the only thing keeping the page from
 * sliding under it is the height every layout reserves through
 * `--demo-banner-height`. That used to be a hardcoded 2.5rem while the copy
 * wrapped to three lines below `md`: the first line was clipped at the top of
 * the viewport and the rest spilled over the conversation underneath.
 *
 * These assertions are about geometry rather than class names, so they hold for
 * any future treatment of the strip: nothing inside it may render past its own
 * bottom edge, the reserved height must be the height it actually occupies, and
 * the countdown chip must keep clear of the copy.
 */

/** Turn the instance into the public demo for the life of the test. */
function useDemoModeForBrowserTests(): void
{
    config(['demo.mode' => true]);
}

/**
 * How far the banner's content spills past the strip, in CSS pixels. Zero when
 * every line is inside the strip; positive is the #841 overflow.
 */
function demoBannerContentOverflow(): string
{
    return <<<'JS'
    (() => {
        const banner = document.querySelector('[data-test="demo-banner"]');

        if (banner === null) {
            return -1;
        }

        const bottom = banner.getBoundingClientRect().bottom;
        const spill = [...banner.querySelectorAll('*')]
            .map((child) => child.getBoundingClientRect())
            .filter((rect) => rect.height > 0)
            .reduce((worst, rect) => Math.max(worst, rect.bottom - bottom), 0);

        return Math.round(spill);
    })()
    JS;
}

/**
 * The gap, in CSS pixels, between the height the layouts reserve and the height
 * the strip really occupies. Zero means nothing hides under it and no dead band
 * sits below it.
 */
function demoBannerReservationGap(): string
{
    return <<<'JS'
    (() => {
        const banner = document.querySelector('[data-test="demo-banner"]');

        if (banner === null) {
            return -1;
        }

        const reserved = Number.parseFloat(
            getComputedStyle(document.documentElement)
                .getPropertyValue('--demo-banner-height'),
        );

        return Math.round(Math.abs(reserved - banner.getBoundingClientRect().height));
    })()
    JS;
}

/** Whether the countdown chip's box overlaps any of the banner's copy. */
function demoCountdownOverlapsCopy(): string
{
    return <<<'JS'
    (() => {
        const chip = document.querySelector('[data-test="demo-reset-countdown"]');

        if (chip === null) {
            return true;
        }

        const chipRect = chip.getBoundingClientRect();

        return [...document.querySelectorAll('[data-test="demo-banner"] span, [data-test="demo-banner"] strong')]
            .filter((element) => ! chip.contains(element) && element !== chip && element.textContent.trim() !== '')
            .some((element) => {
                const rect = element.getBoundingClientRect();

                return rect.height > 0
                    && rect.left < chipRect.right
                    && rect.right > chipRect.left
                    && rect.top < chipRect.bottom
                    && rect.bottom > chipRect.top;
            });
    })()
    JS;
}

/** Where the main pane starts, relative to the bottom of the demo strip. */
function mainPaneOffsetBelowBanner(): string
{
    return <<<'JS'
    (() => {
        const banner = document.querySelector('[data-test="demo-banner"]');
        const main = document.getElementById('main');

        if (banner === null || main === null) {
            return -1;
        }

        return Math.round(
            main.getBoundingClientRect().top - banner.getBoundingClientRect().bottom,
        );
    })()
    JS;
}

test('the demo strip contains its own copy at every phone width', function (int $width, int $height): void {
    useDemoModeForBrowserTests();

    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->resize($width, $height)
        ->navigate(browserChannelUrl($team, $channel))
        ->assertScript(demoBannerContentOverflow(), 0)
        ->assertScript(demoBannerReservationGap(), 0)
        ->assertScript(demoCountdownOverlapsCopy(), false);
})->with([
    'the tightest phone' => [360, 740],
    'iPhone SE' => [375, 667],
    'the design canvas' => [390, 844],
    'a large phone' => [430, 932],
    'the breakpoint itself' => [768, 1024],
    'landscape phone' => [740, 360],
]);

test('the stacked demo strip has no serious accessibility violations, light or dark', function (): void {
    useDemoModeForBrowserTests();

    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    // Only one wording of the notice is ever in the accessibility tree: the
    // treatment each side of `md` hides the other with `display: none`.
    $page = signInThroughBrowser($alice)
        ->resize(360, 740)
        ->navigate(browserChannelUrl($team, $channel))
        ->assertVisible('[data-test="demo-banner"]')
        ->assertNoAccessibilityIssues();

    $page->script(<<<'JS'
    () => {
        localStorage.setItem('appearance', 'dark');
        document.documentElement.classList.add('dark');
        document.documentElement.style.colorScheme = 'dark';
    }
    JS);

    $page->wait(0.5)
        ->assertNoAccessibilityIssues();
});

test('the workspace starts below the demo strip instead of under it', function (): void {
    useDemoModeForBrowserTests();

    ['owner' => $alice, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    // The main pane keeps its own 8px canvas gutter below the strip, so the
    // reservation is honoured without swallowing the layout's own breathing room.
    signInThroughBrowser($alice)
        ->resize(360, 740)
        ->navigate(browserChannelUrl($team, $channel))
        ->assertScript(mainPaneOffsetBelowBanner(), 8);
});
