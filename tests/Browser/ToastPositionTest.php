<?php

declare(strict_types=1);

use App\Models\Message;
use App\Models\User;

// Toasts live in the lower zone of the bottom-right rail: nearest the action
// that raised them, above the composer, and clear of the masthead. This reverses
// #430, which moved them to top-center precisely to stop them covering the
// composer — the rail solves that without landing on the channel name and its
// actions instead (#978).
//
// The rail is viewport-fixed but pane-aware: the conversation pane publishes how
// much of each edge its own furniture claims, and the rail insets by it. That
// contract is invisible coupling between two files, so it is asserted here in
// both directions — tracking a panel that opens and closes, and falling back to
// the viewport edge on a page that publishes nothing.

/** How far the toaster region sits from the viewport's right and bottom edges. */
function browserToasterInset(): string
{
    return <<<'JS'
    (() => {
        const region = document.querySelector('[data-sonner-toaster][data-y-position="bottom"]');
        const style = getComputedStyle(region);

        return `${style.right}|${style.bottom}`;
    })()
    JS;
}

/** The right inset the open conversation pane is currently publishing. */
function browserRailRightInset(): string
{
    return <<<'JS'
    (() => getComputedStyle(document.documentElement)
        .getPropertyValue('--rail-right-inset').trim())()
    JS;
}

test('toasts render in the bottom-right rail in the authenticated shell', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    signInThroughBrowser($alice)
        ->assertAttribute('[data-sonner-toaster]', 'data-y-position', 'bottom')
        ->assertAttribute('[data-sonner-toaster]', 'data-x-position', 'right');
});

test('toasts render in the bottom-right rail on auth pages', function (): void {
    visit('/login')
        ->assertAttribute('[data-sonner-toaster]', 'data-y-position', 'bottom')
        ->assertAttribute('[data-sonner-toaster]', 'data-x-position', 'right');
});

test('the rail clears the composer instead of covering it', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $page = signInThroughBrowser($alice)
        ->assertPresent('[data-tour="composer"]');

    // The rail's floor is the composer's top edge plus the 16px gutter, so a
    // toast never lands on the send button or the scheduled chip.
    $page->assertScript(<<<'JS'
    (() => {
        const composer = document.querySelector('[data-tour="composer"]');
        const region = document.querySelector('[data-sonner-toaster][data-y-position="bottom"]');
        const claimed = window.innerHeight - composer.getBoundingClientRect().top;

        return Math.abs(parseFloat(getComputedStyle(region).bottom) - (claimed + 16)) < 1.5;
    })()
    JS, true);
});

test('the rail tracks the thread panel opening and closing', function (): void {
    ['owner' => $alice, 'channel' => $channel] = browserTeamWithChannel();

    $root = Message::factory()->for($channel)->for($alice)->create([
        'body' => 'Thread root message',
    ]);
    Message::factory()->for($channel)->inThread($root)->create([
        'user_id' => $alice->id,
        'body' => 'A reply',
    ]);
    $root->forceFill(['reply_count' => 1, 'last_reply_at' => now()])->save();

    // Wide enough that the panel is a flex sibling of the conversation pane.
    // Below `lg` it overlays the pane instead, and then there is deliberately
    // nothing for the rail to clear.
    $page = signInThroughBrowser($alice)
        ->resize(1440, 900)
        ->assertSee('Thread root message');

    // Nothing claims the right edge yet, so the rail sits at the viewport's.
    $page->assertScript(browserRailRightInset(), '');

    $page->click('[data-test=thread-summary]')
        ->assertPresent('[data-test=thread-panel]')
        ->wait(0.6);

    // The panel now claims its own width, and the rail insets by it — measured
    // after the enter transition settles, not while it is still translated.
    $page->assertScript(<<<'JS'
    (() => {
        const panel = document.querySelector('[data-test=thread-panel]');
        const claimed = window.innerWidth - panel.getBoundingClientRect().left;
        const published = parseFloat(
            getComputedStyle(document.documentElement).getPropertyValue('--rail-right-inset'),
        );

        return published > 100 && Math.abs(published - claimed) < 1.5;
    })()
    JS, true);

    $page->click('[data-test=thread-close]')
        ->assertMissing('[data-test=thread-panel]')
        ->wait(0.6);

    // Closing gives the edge back rather than stranding the rail inset.
    $page->assertScript(browserRailRightInset(), '');
});

test('the slab is separated by its shadow, since it carries no border', function (): void {
    $alice = User::factory()->create();

    $page = signInThroughBrowser($alice)
        ->navigate('/settings/profile')
        ->assertSee('Profile');

    // Saving flashes a success toast from the server.
    $page->click('[data-test="update-profile-button"]')
        ->assertPresent('[data-test="toast"]');

    // The slab has no border by design, so a shadow that resolves to
    // transparent leaves it floating with nothing separating it from the page.
    // `shadow-[…_var(--shadow-ink)]` did exactly that, silently (#978).
    $page->assertScript(<<<'JS'
    (() => {
        const style = getComputedStyle(document.querySelector('[data-test="toast"]'));
        const shadow = style.boxShadow;

        return style.borderTopWidth === '0px'
            && shadow !== 'none'
            && /rgba?\([^)]*(?:,\s*0?\.\d+)\)\s+0px\s+20px\s+48px/.test(shadow);
    })()
    JS, true);
});

test('the rail falls back to the viewport corner on a page with no conversation pane', function (): void {
    $alice = User::factory()->create();

    // Settings publishes neither inset, so both `var()` fallbacks apply and the
    // rail sits 16px in from each edge — no page has to opt out.
    signInThroughBrowser($alice)
        ->navigate('/settings/profile')
        ->assertSee('Profile')
        ->assertScript(browserToasterInset(), '16px|16px');
});
