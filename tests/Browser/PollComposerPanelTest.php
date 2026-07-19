<?php

declare(strict_types=1);

/**
 * Regression coverage for issue #577: the poll builder rendered but was dead —
 * a `mounted` focus crash wedged Vue's scheduler, so Add option, the × close
 * button, and both switches never updated the panel. These run against the real
 * app so a repeat of that crash fails loudly here.
 */
test('the poll builder opens focused and add/remove option rows work', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $page = signInThroughBrowser($alice);
    $page->assertPresent('@message-composer-input');

    $page
        ->type('@message-composer-input', '/poll')
        ->keys('@message-composer-input', ['Enter'])
        ->assertPresent('@poll-builder')
        ->assertNoJavaScriptErrors()
        ->assertScript(
            'document.activeElement?.dataset.test',
            'poll-question-input',
        )
        ->assertScript(
            'document.querySelectorAll(\'[data-test="poll-option-input"]\').length',
            2,
        );

    $page
        ->click('@poll-add-option')
        ->assertScript(
            'document.querySelectorAll(\'[data-test="poll-option-input"]\').length',
            3,
        )
        ->click('@poll-option-remove')
        ->assertScript(
            'document.querySelectorAll(\'[data-test="poll-option-input"]\').length',
            2,
        )
        ->assertMissing('@poll-option-remove');
});

test('the poll builder switches toggle and the × button closes the panel', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $page = signInThroughBrowser($alice);
    $page->assertPresent('@message-composer-input');

    $page
        ->type('@message-composer-input', '/poll')
        ->keys('@message-composer-input', ['Enter'])
        ->assertPresent('@poll-builder')
        ->assertAttribute('@poll-allow-multiple', 'data-state', 'unchecked')
        ->assertAttribute('@poll-anonymous', 'data-state', 'unchecked')
        ->click('@poll-allow-multiple')
        ->assertAttribute('@poll-allow-multiple', 'data-state', 'checked')
        ->click('@poll-anonymous')
        ->assertAttribute('@poll-anonymous', 'data-state', 'checked')
        ->click('@poll-allow-multiple')
        ->assertAttribute('@poll-allow-multiple', 'data-state', 'unchecked');

    $page
        ->click('@poll-builder-close')
        ->assertMissing('@poll-builder')
        ->assertNoJavaScriptErrors();
});
