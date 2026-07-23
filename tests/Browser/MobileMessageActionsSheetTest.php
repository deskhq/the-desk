<?php

declare(strict_types=1);

use App\Enums\AppLocale;
use App\Models\Message;
use Pest\Browser\Api\AwaitableWebpage;

/**
 * Long-press message actions below the `md` breakpoint (#774).
 *
 * There is no hover on touch, so the hover toolbar's actions are unreachable on
 * a phone. Long-pressing a message lifts it onto a scrim and opens a bottom
 * sheet carrying the same actions, gated by the same guards — nothing the
 * toolbar hides may appear in the sheet.
 */

/**
 * Press and hold a message row: pointer down, wait out the 500ms hold, lift.
 *
 * Synthesised as touch on purpose — the gesture is the touch stand-in for
 * hover. The hold happens between two script calls so the browser's own clock
 * drives the composable's timer.
 */
function longPressMessage(AwaitableWebpage $page, string $messageId): AwaitableWebpage
{
    $press = fn (string $type): string => <<<JS
    (() => {
        const row = document.getElementById('message-{$messageId}');
        const box = row.getBoundingClientRect();

        row.dispatchEvent(new PointerEvent('{$type}', {
            pointerId: 1,
            pointerType: 'touch',
            isPrimary: true,
            bubbles: true,
            cancelable: true,
            clientX: Math.round(box.x + 40),
            clientY: Math.round(box.y + 8),
        }));
    })()
    JS;

    $page->script($press('pointerdown'));
    $page = $page->wait(0.7);
    $page->script($press('pointerup'));

    return $page;
}

test('a long-press on a peer message opens the sheet without the author-only actions', function (): void {
    ['owner' => $alice, 'member' => $bob, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $alice->id,
        'body' => 'Shipped the thread panel today.',
    ]);

    $page = signInThroughBrowser($bob)
        ->resize(390, 844)
        ->navigate(browserChannelUrl($team, $channel))
        ->assertPresent("#message-{$message->id}");

    longPressMessage($page, $message->id)
        ->assertPresent('@message-actions-sheet')
        // The pressed message stays visible, lifted above the sheet.
        ->assertPresent('@lifted-message')
        ->assertSee('Shipped the thread panel today.')
        // Every action the hover toolbar would offer Bob on Alice's message...
        ->assertPresent('@sheet-quick-react')
        ->assertPresent('@sheet-thread')
        ->assertPresent('@sheet-reply')
        ->assertPresent('@sheet-forward')
        ->assertPresent('@sheet-pin')
        ->assertPresent('@sheet-remind')
        // ...and nothing it would hide: Bob may neither edit nor delete it.
        ->assertNotPresent('@sheet-edit')
        ->assertNotPresent('@sheet-delete');
});

test('a long-press on your own message offers edit and delete', function (): void {
    ['member' => $bob, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $bob->id,
        'body' => 'My own words, mine to edit.',
    ]);

    $page = signInThroughBrowser($bob)
        ->resize(390, 844)
        ->navigate(browserChannelUrl($team, $channel))
        ->assertPresent("#message-{$message->id}");

    longPressMessage($page, $message->id)
        ->assertPresent('@message-actions-sheet')
        ->assertPresent('@sheet-edit')
        ->assertPresent('@sheet-delete');
});

test('from md up a long-press opens nothing and the hover toolbar still stands', function (): void {
    ['owner' => $alice, 'member' => $bob, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $alice->id,
        'body' => 'Desktop keeps its hover bar.',
    ]);

    $page = signInThroughBrowser($bob)
        ->resize(768, 1024)
        ->navigate(browserChannelUrl($team, $channel))
        ->assertPresent("#message-{$message->id}");

    longPressMessage($page, $message->id)
        ->assertNotPresent('@message-actions-sheet')
        // The desktop affordance is untouched: the toolbar is in the row,
        // revealed by hover.
        ->assertPresent('@message-reply');
});

test('a landscape phone gets the sheet but folds the lifted card away', function (): void {
    ['owner' => $alice, 'member' => $bob, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $alice->id,
        'body' => 'A short viewport leaves no scrim to sit on.',
    ]);

    $page = signInThroughBrowser($bob)
        ->resize(740, 360)
        ->navigate(browserChannelUrl($team, $channel))
        ->assertPresent("#message-{$message->id}");

    longPressMessage($page, $message->id)
        ->assertPresent('@message-actions-sheet')
        // The 85dvh sheet leaves ~54px of scrim — nowhere for a card that
        // must not poke off the top of the screen.
        ->assertScript(<<<'JS'
        (() => {
            const card = document.querySelector('[data-test="lifted-message"]');

            return card === null || getComputedStyle(card).display === 'none';
        })()
        JS, true)
        // Every action row still lands inside the viewport or scrolls to it.
        ->assertScript(<<<'JS'
        (() => {
            const sheet = document.querySelector('[data-test="message-actions-sheet"]');

            return sheet.getBoundingClientRect().height <= window.innerHeight;
        })()
        JS, true);
});

test('the sheet survives French at the tightest width', function (): void {
    ['owner' => $alice, 'member' => $bob, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();
    $bob->update(['locale' => AppLocale::French]);

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $alice->id,
        'body' => 'Bonjour à toutes et à tous.',
    ]);

    $page = signInThroughBrowser($bob)
        ->resize(360, 740)
        ->navigate(browserChannelUrl($team, $channel))
        ->assertPresent("#message-{$message->id}");

    longPressMessage($page, $message->id)
        ->assertPresent('@message-actions-sheet')
        // The catalog has actually taken, and the longer French labels fit.
        ->assertSee('Répondre au fil')
        ->assertSee('Me le rappeler…')
        ->assertScript(<<<'JS'
        (() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)()
        JS, true);
});

test('the open sheet has no serious accessibility violations in either theme', function (): void {
    ['owner' => $alice, 'member' => $bob, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $alice->id,
        'body' => 'Audit this sheet.',
    ]);

    // Mark the message read so no unread divider or jump pill renders — those
    // carry contrast debt tracked separately (see A11yAuditTest).
    $bob->channels()->updateExistingPivot($channel->id, [
        'last_read_message_id' => $message->id,
    ]);

    $page = signInThroughBrowser($bob)
        ->resize(390, 844)
        ->navigate(browserChannelUrl($team, $channel))
        ->assertPresent("#message-{$message->id}");

    // Settle after the 200ms slide-in before the non-retrying axe assertion,
    // as the sheet primitive's own audit does.
    $page = longPressMessage($page, $message->id)
        ->assertPresent('@message-actions-sheet')
        ->wait(0.5)
        ->assertNoAccessibilityIssues();

    $page->script(<<<'JS'
    () => {
        localStorage.setItem('appearance', 'dark');
        document.documentElement.classList.add('dark');
        document.documentElement.style.colorScheme = 'dark';
    }
    JS);

    $page->wait(0.5)->assertNoAccessibilityIssues();
});
