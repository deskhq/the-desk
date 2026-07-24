<?php

declare(strict_types=1);

use App\Actions\Channels\OpenDirectMessage;
use App\Models\Team;
use App\Models\User;

/**
 * The composer placeholder stays on a single line (#802).
 *
 * On a phone, a DM with a long-named counterpart used to wrap its
 * "Message <name>" placeholder onto three lines, growing the empty composer
 * as if it were pre-filled. The rendered placeholder is now ellipsized to the
 * textarea's width while the accessible name keeps the full text.
 */
const COMPOSER_FULL_PLACEHOLDER = 'Message Bartholomew Montgomery Featherstone';

/**
 * A team whose owner has a 1:1 DM open with a long-named counterpart — the
 * name whose placeholder wrapped the empty composer on a phone.
 *
 * @return array{owner: User, team: Team, url: string}
 */
function browserDmWithLongRecipient(): array
{
    ['owner' => $alice, 'member' => $bob, 'team' => $team] = browserTeamWithChannel();

    $bob->update(['name' => 'Bartholomew Montgomery Featherstone']);

    $dm = app(OpenDirectMessage::class)->handle($team, $alice, $bob);

    return ['owner' => $alice, 'team' => $team, 'url' => browserChannelUrl($team, $dm)];
}

test('the placeholder is ellipsized to a single line at phone widths', function (int $width, int $height): void {
    ['owner' => $alice, 'url' => $url] = browserDmWithLongRecipient();

    signInThroughBrowser($alice)
        ->resize($width, $height)
        ->navigate($url)
        ->assertVisible('@message-composer-input')
        // The rendered placeholder is truncated with an ellipsis...
        ->assertScript(<<<'JS'
        (() => {
            const placeholder = document.querySelector('[data-test="message-composer-input"]').placeholder;

            return placeholder.endsWith('…')
                && placeholder.length < 'Message Bartholomew Montgomery Featherstone'.length;
        })()
        JS, true)
        // ...so the empty composer stands one line tall, not three.
        ->assertScript(<<<'JS'
        (() => {
            const el = document.querySelector('[data-test="message-composer-input"]');

            return el.getBoundingClientRect().height
                < 2 * Number.parseFloat(getComputedStyle(el).lineHeight);
        })()
        JS, true);
})->with([
    'small phone' => [360, 740],
    'iPhone 14' => [390, 844],
]);

test('the accessible name keeps the full untruncated text', function (): void {
    ['owner' => $alice, 'url' => $url] = browserDmWithLongRecipient();

    signInThroughBrowser($alice)
        ->resize(390, 844)
        ->navigate($url)
        ->assertAttribute(
            '@message-composer-input',
            'aria-label',
            COMPOSER_FULL_PLACEHOLDER,
        );
});

test('the empty composer with a long placeholder matches the single-line height it has once typed in', function (): void {
    ['owner' => $alice, 'url' => $url] = browserDmWithLongRecipient();

    $page = signInThroughBrowser($alice)
        ->resize(390, 844)
        ->navigate($url)
        ->assertVisible('@message-composer-input');

    $page->script(<<<'JS'
    (() => {
        const el = document.querySelector('[data-test="message-composer-input"]');

        window.__emptyComposerHeight = Math.round(el.getBoundingClientRect().height);
    })()
    JS);

    // One typed character is the canonical single-line composer; the empty
    // composer (placeholder only) must be exactly as tall.
    $page->type('@message-composer-input', 'x')
        ->assertScript(<<<'JS'
        (() => {
            const el = document.querySelector('[data-test="message-composer-input"]');

            return Math.round(el.getBoundingClientRect().height) === window.__emptyComposerHeight;
        })()
        JS, true);
});

test('the full name returns once the composer is wide enough', function (): void {
    ['owner' => $alice, 'url' => $url] = browserDmWithLongRecipient();

    // Truncation follows the textarea's own width, not the viewport: on a
    // desktop-wide window the whole name fits, so nothing is ellipsized.
    signInThroughBrowser($alice)
        ->resize(1440, 900)
        ->navigate($url)
        ->assertAttribute(
            '@message-composer-input',
            'placeholder',
            COMPOSER_FULL_PLACEHOLDER,
        );
});
