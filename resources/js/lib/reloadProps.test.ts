import { describe, expect, it } from 'vitest';
import {
    AUTH_PROPS,
    CHANNEL_LIST_PROPS,
    CHANNEL_SECTION_PROPS,
    COLLAPSED_SECTION_PROPS,
    FREQUENT_EMOJI_PROPS,
    LOCALE_PROPS,
    PIN_PROPS,
    POST_REGISTRATION_PROMPT_PROPS,
    SCHEDULED_MESSAGE_PROPS,
    TEAM_MEMBER_PROPS,
    THREAD_PROPS,
    THREAD_RESET_PROPS,
    UNREAD_DIGEST_PROPS,
    WORKSPACE_PROPS,
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
    it('names the viewer, so an account write need not ask for the whole page', () => {
        expect(AUTH_PROPS).toEqual(['auth']);
    });

    it('names the sidebar channel read-model', () => {
        expect(CHANNEL_LIST_PROPS).toEqual(['channels']);
    });

    it('keeps the unread digest apart from the roster it badges', () => {
        // Marking a channel read used to reload the whole roster to move four
        // integers; the badges are their own prop, so the write asks for that.
        expect(UNREAD_DIGEST_PROPS).toEqual(['unread']);
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

    it('names everything the server renders in the reader own language', () => {
        // A locale the reader has already used this session is one the client
        // believes it holds, so moving back to it restores the copy of the
        // language they just left unless these are asked for by name (#1251).
        // The workspace list is here for `roleLabel`, which the server
        // translates like any other piece of copy (#1253).
        expect(LOCALE_PROPS).toEqual([
            'locale',
            'translations',
            'slashCommands',
            'sidebarPositions',
            'invitableRoles',
            'teams',
            'currentTeam',
        ]);
    });

    it('names the member roster, so a teammate write need not ask for the page', () => {
        // This reload carried no `only` at all until #1252: a whole page of
        // props, on a teammate's timing, to move a do-not-disturb crescent.
        expect(TEAM_MEMBER_PROPS).toEqual(['teamMembers']);
    });

    it('names the quick-react ranking, which only a reaction re-ranks', () => {
        expect(FREQUENT_EMOJI_PROPS).toEqual(['frequentEmojis']);
    });

    it('names everything one workspace answers, for the move that is not a write', () => {
        // Switching workspace changes every one of these at once. They are
        // `once` props keyed by their team, which answers moving to a workspace
        // the client has not seen; returning to one it has finds the key
        // declared and restores the rosters of the workspace just left (#1252).
        expect(WORKSPACE_PROPS).toEqual([
            'channels',
            'channelSections',
            'frequentEmojis',
            'reminders',
            'firedReminders',
        ]);
    });

    it('names the one-time security prompt, which is cached while empty', () => {
        expect(POST_REGISTRATION_PROMPT_PROPS).toEqual([
            'postRegistrationPrompt',
        ]);
    });

    it.each([
        ['AUTH_PROPS', arrayLiteralOf(/'auth'/)],
        ['CHANNEL_LIST_PROPS', arrayLiteralOf(/'channels'/)],
        ['CHANNEL_SECTION_PROPS', arrayLiteralOf(/'channelSections'/)],
        [
            'COLLAPSED_SECTION_PROPS',
            arrayLiteralOf(/'collapsedChannelSections'/),
        ],
        [
            'LOCALE_PROPS',
            arrayLiteralOf(
                /'locale',\s*'translations',\s*'slashCommands',\s*'sidebarPositions',\s*'invitableRoles',\s*'teams',\s*'currentTeam',?/,
            ),
        ],
        ['PIN_PROPS', arrayLiteralOf(/'pins',\s*'pinCount'/)],
        [
            'POST_REGISTRATION_PROMPT_PROPS',
            arrayLiteralOf(/'postRegistrationPrompt',?/),
        ],
        ['FREQUENT_EMOJI_PROPS', arrayLiteralOf(/'frequentEmojis'/)],
        ['SCHEDULED_MESSAGE_PROPS', arrayLiteralOf(/'scheduledMessages'/)],
        ['TEAM_MEMBER_PROPS', arrayLiteralOf(/'teamMembers'/)],
        ['THREAD_PROPS', arrayLiteralOf(/'thread',\s*'threadReplies'/)],
        ['THREAD_RESET_PROPS', arrayLiteralOf(/'threadReplies'/)],
        ['UNREAD_DIGEST_PROPS', arrayLiteralOf(/'unread'/)],
    ])('is the only place %s is spelled out', (_name, pattern) => {
        // Sixteen copies of `only: ['channels']` across twelve files is what
        // this replaces: a surface refreshing one prop of a pair goes stale, and
        // adding a prop to a set meant finding every copy of it (#1115).
        expect(filesSpelling(pattern, HOME)).toEqual([]);
    });
});
