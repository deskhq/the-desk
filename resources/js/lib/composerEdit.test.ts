import { describe, expect, it } from 'vitest';
import {
    isComposerEditTrigger,
    resolveComposerEditTarget,
} from '@/lib/composerEdit';
import type { ComposerEditTriggerState } from '@/lib/composerEdit';
import type { Message } from '@/types';

/** A message carrying just the fields the edit resolver reads. */
function message(overrides: Partial<Message> = {}): Message {
    return {
        id: 'm1',
        clientUuid: 'uuid-1',
        body: 'hello',
        type: 'standard',
        user: { id: 'me', name: 'Me' },
        createdAt: '2024-01-01T00:00:00.000Z',
        editedAt: null,
        isDeleted: false,
        mentions: [],
        linkPreviews: [],
        attachments: [],
        reactions: [],
        pin: null,
        replyTo: null,
        forwardedFrom: null,
        threadRootId: null,
        sentToChannel: false,
        threadReplyCount: 0,
        threadLastReplyAt: null,
        threadParticipants: [],
        threadFollowed: false,
        threadUnread: false,
        ...overrides,
    };
}

/** A trigger state that would fire, so each test flips a single field. */
function triggerState(
    overrides: Partial<ComposerEditTriggerState> = {},
): ComposerEditTriggerState {
    return {
        key: 'ArrowUp',
        altKey: false,
        ctrlKey: false,
        metaKey: false,
        shiftKey: false,
        menuOpen: false,
        editing: false,
        hasReplyTarget: false,
        isEmpty: true,
        caretAtStart: true,
        ...overrides,
    };
}

describe('isComposerEditTrigger', () => {
    it('fires on a bare ArrowUp in an empty composer with the caret at start', () => {
        expect(isComposerEditTrigger(triggerState())).toBe(true);
    });

    it('ignores keys other than ArrowUp', () => {
        expect(isComposerEditTrigger(triggerState({ key: 'ArrowDown' }))).toBe(
            false,
        );
    });

    it.each(['altKey', 'ctrlKey', 'metaKey', 'shiftKey'] as const)(
        'does not fire when %s is held',
        (modifier) => {
            expect(
                isComposerEditTrigger(triggerState({ [modifier]: true })),
            ).toBe(false);
        },
    );

    it('does not fire while the mention menu is open', () => {
        expect(isComposerEditTrigger(triggerState({ menuOpen: true }))).toBe(
            false,
        );
    });

    it('does not fire when already editing', () => {
        expect(isComposerEditTrigger(triggerState({ editing: true }))).toBe(
            false,
        );
    });

    it('does not fire while a reply is being composed', () => {
        expect(
            isComposerEditTrigger(triggerState({ hasReplyTarget: true })),
        ).toBe(false);
    });

    it('does not fire when the composer has text', () => {
        expect(isComposerEditTrigger(triggerState({ isEmpty: false }))).toBe(
            false,
        );
    });

    it('does not fire when the caret is not at the start', () => {
        expect(
            isComposerEditTrigger(triggerState({ caretAtStart: false })),
        ).toBe(false);
    });
});

describe('resolveComposerEditTarget', () => {
    it('returns the viewer’s most recent editable message', () => {
        const messages = [
            message({ id: 'a', clientUuid: 'a' }),
            message({ id: 'b', clientUuid: 'b', body: 'newer' }),
        ];

        expect(resolveComposerEditTarget(messages, 'me')?.id).toBe('b');
    });

    it('skips messages authored by someone else', () => {
        const messages = [
            message({ id: 'mine', clientUuid: 'mine' }),
            message({
                id: 'theirs',
                clientUuid: 'theirs',
                user: { id: 'peer', name: 'Peer' },
            }),
        ];

        expect(resolveComposerEditTarget(messages, 'me')?.id).toBe('mine');
    });

    it('skips deleted messages and system notices', () => {
        const messages = [
            message({ id: 'live', clientUuid: 'live' }),
            message({ id: 'gone', clientUuid: 'gone', isDeleted: true }),
            message({ id: 'sys', clientUuid: 'sys', type: 'member_left' }),
        ];

        expect(resolveComposerEditTarget(messages, 'me')?.id).toBe('live');
    });

    it('skips in-flight optimistic sends still held as pending', () => {
        const messages = [
            message({ id: 'settled', clientUuid: 'settled' }),
            message({ id: 'sending', clientUuid: 'sending' }),
        ];

        expect(resolveComposerEditTarget(messages, 'me', ['sending'])?.id).toBe(
            'settled',
        );
    });

    it('returns null when the viewer has no editable message', () => {
        const messages = [
            message({
                id: 'theirs',
                clientUuid: 'theirs',
                user: { id: 'peer', name: 'Peer' },
            }),
        ];

        expect(resolveComposerEditTarget(messages, 'me')).toBeNull();
        expect(resolveComposerEditTarget([], 'me')).toBeNull();
    });
});
