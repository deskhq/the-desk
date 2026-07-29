<?php

declare(strict_types=1);

use App\Enums\MessageType;
use App\Models\Message;

/**
 * The channel-details modal reached from the masthead's options menu: what it
 * says about the channel, and the edit form behind it.
 *
 * The Vitest suite covers the component's own gating against stubbed dialog
 * primitives; what only a real browser can prove is the round trip — reka's
 * dialog, the Inertia form actually PATCHing, and the masthead and timeline
 * reflecting the result — plus that the modal clears an axe audit in both
 * themes.
 */
test('a member reads the channel purpose and edits it from the masthead', function (): void {
    ['owner' => $alice, 'channel' => $channel] = browserTeamWithChannel();

    $channel->update([
        'topic' => 'Everything about Acme',
        'description' => 'The **first** room, see https://example.com',
    ]);

    $page = signInThroughBrowser($alice)
        ->click('@channel-options')
        ->click('@channel-details')
        ->assertPresent('@channel-details-dialog')
        ->assertSee('Everything about Acme')
        // The description renders through the message renderer, so its Markdown
        // and its bare URL come back as real markup rather than literal text.
        ->assertScript(
            '!!document.querySelector(\'[data-test="channel-details-description-value"] strong\')',
            true,
        )
        ->assertScript(
            'document.querySelector(\'[data-test="channel-details-description-value"] a\')?.href',
            'https://example.com/',
        );

    suppressTransitions($page)->assertNoAccessibilityIssues();

    switchToDarkTheme($page)->assertNoAccessibilityIssues();

    $page->click('@channel-details-edit')
        ->fill('@channel-details-topic', 'Launch coordination')
        ->fill('@channel-details-description', 'Where the launch is run from.')
        ->click('@channel-details-save')
        // The masthead reads the saved topic back, so the page picked the edit up.
        ->assertSeeIn('@masthead-topic', 'Launch coordination');

    expect($channel->fresh())
        ->topic->toBe('Launch coordination')
        ->description->toBe('Where the launch is run from.');

    // The topic change leaves its own line in the timeline; the description edit
    // beside it stays silent.
    expect(Message::where('channel_id', $channel->id)->pluck('type')->all())
        ->toBe([MessageType::TopicChanged]);
});

test('an edit reaches another member without them reloading', function (): void {
    ['owner' => $alice, 'member' => $bob] = browserTeamWithChannel();

    $alicePage = signInThroughBrowser($alice);
    $bobPage = signInThroughBrowser($bob);

    // Bob is sitting in the channel, subscribed, before Alice touches anything.
    $bobPage->assertPresent('@message-composer-input');

    $alicePage
        ->click('@channel-options')
        ->click('@channel-details')
        ->click('@channel-details-edit')
        ->fill('@channel-details-topic', 'Launch coordination')
        ->fill('@channel-details-description', 'Where the launch is run from.')
        ->click('@channel-details-save')
        ->assertSeeIn('@masthead-topic', 'Launch coordination');

    // The masthead Bob is already looking at re-reads the topic off the
    // ChannelUpdated broadcast — asserted on the masthead itself, since the
    // topic notice in his timeline quotes the same words.
    $bobPage->assertSeeIn('@masthead-topic', 'Launch coordination');

    // The description edit is silent in the timeline, so the broadcast is the
    // only thing that could have refreshed what his modal now reads.
    $bobPage
        ->click('@channel-options')
        ->click('@channel-details')
        ->assertSeeIn(
            '@channel-details-description-value',
            'Where the launch is run from.',
        );
});
