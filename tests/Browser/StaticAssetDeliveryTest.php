<?php

declare(strict_types=1);

/**
 * The browser suite is served in-process (see
 * `tests/Browser/Support/LaravelHttpServer.php`), and every assertion that
 * reads a rendered box silently depends on the stylesheet having arrived. When
 * the server closed a keep-alive connection the browser was about to reuse, the
 * document rendered unstyled and the failure surfaced as a bogus layout
 * regression somewhere else entirely (#944). These tests fail loudly instead.
 */
test('a second full navigation still applies every stylesheet', function (): void {
    ['owner' => $alice, 'team' => $team] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->navigate("/t/{$team->slug}/channels/browse")
        ->assertScript(<<<'JS'
        (() => {
            const links = [...document.querySelectorAll('link[rel="stylesheet"]')];

            return links.length > 0 && links.every((link) => link.sheet !== null);
        })()
        JS, true);
});

test('a second full navigation still resolves the theme variables the stylesheet defines', function (): void {
    ['owner' => $alice, 'team' => $team] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->navigate("/t/{$team->slug}/channels/browse")
        ->assertScript(<<<'JS'
        (() => getComputedStyle(document.documentElement)
            .getPropertyValue('--spacing').trim() !== '')()
        JS, true);
});
