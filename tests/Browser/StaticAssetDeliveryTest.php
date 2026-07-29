<?php

declare(strict_types=1);

/**
 * The browser suite is served in-process (see
 * `tests/Browser/Support/LaravelHttpServer.php`), and every assertion that
 * reads a rendered box silently depends on the stylesheet having arrived. When
 * an asset is lost the document renders unstyled and the failure surfaces as a
 * bogus layout regression somewhere else entirely (#944). These tests fail
 * loudly instead.
 *
 * Both report their diagnosis rather than a bare `false`, because the whole
 * point of the issue is that this failure is easy to misread: an assertion that
 * only says "expected true" sends the next person looking at the component.
 */
test('a second full navigation still applies every stylesheet', function (): void {
    ['owner' => $alice, 'team' => $team] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->navigate("/t/{$team->slug}/channels/browse")
        ->assertScript(<<<'JS'
        (() => {
            const links = [...document.querySelectorAll('link[rel="stylesheet"]')];

            if (links.length === 0) {
                return 'no stylesheet links in the document at all';
            }

            const dropped = links.filter((link) => link.sheet === null);

            if (dropped.length === 0) {
                return 'every stylesheet applied';
            }

            const timing = performance.getEntriesByType('resource')
                .filter((entry) => entry.name.endsWith('.css') || entry.name.includes('pest-retry'))
                .map((entry) => [
                    entry.name.split('/').pop(),
                    `status=${entry.responseStatus}`,
                    `bytes=${entry.transferSize}`,
                    `protocol=${entry.nextHopProtocol || 'none'}`,
                    `start=${Math.round(entry.startTime)}ms`,
                    `duration=${Math.round(entry.duration)}ms`,
                ].join(' '));

            return `dropped ${dropped.map((link) => link.href.split('/').pop()).join(', ')}`
                + ` | css requests: ${timing.join(' // ')}`;
        })()
        JS, 'every stylesheet applied');
});

test('a second full navigation still resolves the theme variables the stylesheet defines', function (): void {
    ['owner' => $alice, 'team' => $team] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->navigate("/t/{$team->slug}/channels/browse")
        ->assertScript(<<<'JS'
        (() => {
            const spacing = getComputedStyle(document.documentElement)
                .getPropertyValue('--spacing').trim();

            return spacing === ''
                ? 'the Tailwind theme variables never resolved, so the document is unstyled'
                : 'the theme variables resolved';
        })()
        JS, 'the theme variables resolved');
});
