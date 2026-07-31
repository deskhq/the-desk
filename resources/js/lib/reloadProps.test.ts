import { describe, expect, it } from 'vitest';
import {
    CHANNEL_LIST_PROPS,
    CHANNEL_SECTION_PROPS,
    COLLAPSED_SECTION_PROPS,
    PIN_PROPS,
    SCHEDULED_MESSAGE_PROPS,
    THREAD_PROPS,
    THREAD_RESET_PROPS,
} from '@/lib/reloadProps';
import { filesSpelling } from '@/lib/sourceScan.harness';

/** The module that owns the sets; every other site imports them from here. */
const HOME = 'lib/reloadProps.ts';

/**
 * A set spelled out as an array literal. The leading lookbehind keeps an
 * indexed access — `TokenLookup['channels']`, which is a type, not a prop set —
 * from reading as one.
 */
function arrayLiteralOf(members: RegExp): RegExp {
    return new RegExp(`(?<![\\w\\]])\\[\\s*${members.source}\\s*\\]`);
}

describe('reloadProps', () => {
    it('names the sidebar channel read-model', () => {
        expect(CHANNEL_LIST_PROPS).toEqual(['channels']);
    });

    it('names the sidebar section read-model', () => {
        expect(CHANNEL_SECTION_PROPS).toEqual(['channelSections']);
    });

    it('keeps the built-in groups collapse set apart from the custom sections', () => {
        // A custom section carries its collapse flag on its own row; the
        // built-in groups have no rows, so their set lives on the account.
        expect(COLLAPSED_SECTION_PROPS).toEqual(['collapsedChannelSections']);
    });

    it('names the later-delivery tray', () => {
        expect(SCHEDULED_MESSAGE_PROPS).toEqual(['scheduledMessages']);
    });

    it('names both pin props, since a pin write moves the count too', () => {
        // The masthead reads `pinCount` and the panel reads `pins`. Refreshing
        // one leaves the badge and the list disagreeing about the same message.
        expect(PIN_PROPS).toEqual(['pins', 'pinCount']);
    });

    it('names both thread props, since a root arrives with its replies', () => {
        expect(THREAD_PROPS).toEqual(['thread', 'threadReplies']);
    });

    it('resets only the paginated half of the thread pair', () => {
        // `threadReplies` is a merging paginator: without the reset, opening a
        // second thread appends its first page to the previous thread's.
        expect(THREAD_RESET_PROPS).toEqual(['threadReplies']);
    });

    it.each([
        ['CHANNEL_LIST_PROPS', arrayLiteralOf(/'channels'/)],
        ['CHANNEL_SECTION_PROPS', arrayLiteralOf(/'channelSections'/)],
        [
            'COLLAPSED_SECTION_PROPS',
            arrayLiteralOf(/'collapsedChannelSections'/),
        ],
        ['PIN_PROPS', arrayLiteralOf(/'pins',\s*'pinCount'/)],
        ['SCHEDULED_MESSAGE_PROPS', arrayLiteralOf(/'scheduledMessages'/)],
        ['THREAD_PROPS', arrayLiteralOf(/'thread',\s*'threadReplies'/)],
    ])('is the only place %s is spelled out', (_name, pattern) => {
        // Sixteen copies of `only: ['channels']` across twelve files is what
        // this replaces: a surface refreshing one prop of a pair goes stale, and
        // adding a prop to a set meant finding every copy of it (#1115).
        expect(filesSpelling(pattern, HOME)).toEqual([]);
    });
});
