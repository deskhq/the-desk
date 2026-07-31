import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';

const { post, patch, destroy, toastError } = vi.hoisted(() => ({
    post: vi.fn(),
    patch: vi.fn(),
    destroy: vi.fn(),
    toastError: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    router: { post, patch, delete: destroy },
}));
vi.mock('@/composables/useToast', () => {
    const toast = {
        error: toastError,
        success: vi.fn(),
        warning: vi.fn(),
        progress: vi.fn(),
    };

    return { useToast: () => toast };
});

import { useMessageStream } from '@/composables/useMessageStream';
import {
    snapshotRef,
    snapshotStreams,
    useOptimisticWrite,
} from '@/composables/useOptimisticWrite';
import { CHANNEL_LIST_PROPS } from '@/lib/reloadProps';
import type { Message } from '@/types';

/** A message carrying just the fields the streams key and patch on. */
function message(overrides: Partial<Message> = {}): Message {
    return {
        id: 'm1',
        clientUuid: 'uuid-1',
        body: 'hello',
        type: 'standard',
        user: { id: 'peer', name: 'Peer' },
        createdAt: '2024-01-01T00:00:00.000Z',
        editedAt: null,
        isDeleted: false,
        mentions: [],
        linkPreviews: [],
        attachments: [],
        reactions: [],
        pin: null,
        poll: null,
        replyTo: null,
        forwardedFrom: null,
        threadRootId: null,
        sentToChannel: false,
        threadReplyCount: 0,
        threadLastReplyAt: null,
        threadParticipants: [],
        threadFollowed: false,
        threadUnread: false,
        threadUnreadReplyCount: 0,
        ...overrides,
    };
}

/** The options bag (third argument) of the nth recorded call on a mock. */
function optionsOf(
    mock: typeof post,
    call = 0,
): {
    only?: string[];
    async?: boolean;
    preserveUrl?: boolean;
    preserveScroll?: boolean;
    preserveState?: boolean;
    onSuccess?: (page: unknown) => void;
    onError: (errors: Record<string, string>) => void;
} {
    return mock.mock.calls[call][2];
}

describe('useOptimisticWrite', () => {
    beforeEach(() => {
        post.mockClear();
        patch.mockClear();
        destroy.mockClear();
        toastError.mockClear();
    });

    it('applies the mutation and posts, leaving the reader where they are', () => {
        const { write } = useOptimisticWrite();
        const starred = ref(false);

        write({
            capture: () => snapshotRef(starred),
            apply: () => {
                starred.value = true;
            },
            url: '/acme/general/star',
            data: { starred: true },
            only: CHANNEL_LIST_PROPS,
            failure: 'Failed to update the channel',
        });

        expect(starred.value).toBe(true);
        expect(post).toHaveBeenCalledWith(
            '/acme/general/star',
            { starred: true },
            expect.objectContaining({
                only: ['channels'],
                preserveScroll: true,
                preserveState: true,
            }),
        );
    });

    it('leaves the mutation standing while the write is in flight', () => {
        const { write } = useOptimisticWrite();
        const starred = ref(false);

        write({
            capture: () => snapshotRef(starred),
            apply: () => {
                starred.value = true;
            },
            url: '/acme/general/star',
            failure: 'Failed to update the channel',
        });

        expect(starred.value).toBe(true);
        expect(toastError).not.toHaveBeenCalled();
    });

    it('restores the snapshot when the write is refused', () => {
        const { write } = useOptimisticWrite();
        const starred = ref(false);

        write({
            capture: () => snapshotRef(starred),
            apply: () => {
                starred.value = true;
            },
            url: '/acme/general/star',
            failure: 'Failed to update the channel',
        });
        optionsOf(post).onError({});

        expect(starred.value).toBe(false);
    });

    it('says what went wrong when the write is refused', () => {
        const { write } = useOptimisticWrite();

        write({
            capture: () => () => undefined,
            url: '/acme/general/star',
            failure: 'Failed to update the channel',
        });
        optionsOf(post).onError({});

        expect(toastError).toHaveBeenCalledWith('Failed to update the channel');
    });

    it('prefers the sentence the server refused with, when there is one', () => {
        const { write } = useOptimisticWrite();

        write({
            capture: () => () => undefined,
            url: '/acme/general/pins',
            failure: (errors) => errors.message ?? 'Failed to pin the message',
        });
        optionsOf(post).onError({
            message: 'This channel has 100 pins already',
        });

        expect(toastError).toHaveBeenCalledWith(
            'This channel has 100 pins already',
        );
    });

    it('reads a payload function after applying, so it posts the new value', () => {
        const { write } = useOptimisticWrite();
        const starred = ref(false);

        write({
            capture: () => snapshotRef(starred),
            apply: () => {
                starred.value = !starred.value;
            },
            url: '/acme/general/star',
            data: () => ({ starred: starred.value }),
            failure: 'Failed to update the channel',
        });

        expect(post.mock.calls[0][1]).toEqual({ starred: true });
    });

    it('captures before applying, so the snapshot predates the mutation', () => {
        const { write } = useOptimisticWrite();
        const level = ref('all');

        write({
            capture: () => snapshotRef(level),
            apply: () => {
                level.value = 'mentions';
            },
            url: '/acme/general/preferences',
            failure: 'Failed to update notification preferences',
        });
        optionsOf(post).onError({});

        expect(level.value).toBe('all');
    });

    it('rolls both streams back together when a two-stream write fails', () => {
        const { write } = useOptimisticWrite();
        const row = message();
        const mainStream = useMessageStream(ref([row]));
        const threadStream = useMessageStream(ref([row]));

        write({
            capture: () =>
                snapshotStreams(row.clientUuid, mainStream, threadStream),
            apply: () => {
                mainStream.patchPin(row.id, {
                    pinnedBy: { id: 'me', name: 'Me' },
                    pinnedAt: '2024-01-02T00:00:00.000Z',
                });
                threadStream.patchPin(row.id, {
                    pinnedBy: { id: 'me', name: 'Me' },
                    pinnedAt: '2024-01-02T00:00:00.000Z',
                });
            },
            url: '/acme/general/m1/pin',
            failure: 'Failed to pin the message. Please try again.',
        });

        expect(mainStream.displayMessages.value[0].pin).not.toBeNull();
        expect(threadStream.displayMessages.value[0].pin).not.toBeNull();

        optionsOf(post).onError({});

        expect(mainStream.displayMessages.value[0].pin).toBeNull();
        expect(threadStream.displayMessages.value[0].pin).toBeNull();
    });

    it('restores a stream that already carried a patch to that same patch', () => {
        const { write } = useOptimisticWrite();
        const row = message();
        const stream = useMessageStream(ref([row]));

        stream.applyPatch({ ...row, body: 'edited once' });

        write({
            capture: () => snapshotStreams(row.clientUuid, stream),
            apply: () => stream.applyPatch({ ...row, body: 'edited twice' }),
            url: '/acme/general/m1',
            method: 'patch',
            failure: 'Your edit failed to save',
        });
        optionsOf(patch).onError({});

        expect(stream.displayMessages.value[0].body).toBe('edited once');
    });

    it('posts a delete with its options in the argument slot delete uses', () => {
        const { write } = useOptimisticWrite();

        write({
            capture: () => () => undefined,
            url: '/acme/general/m1',
            method: 'delete',
            only: CHANNEL_LIST_PROPS,
            failure: 'Failed to delete the message',
        });

        expect(destroy).toHaveBeenCalledWith(
            '/acme/general/m1',
            expect.objectContaining({ only: ['channels'] }),
        );
    });

    it('rolls a delete back through the options it was given', () => {
        const { write } = useOptimisticWrite();
        const shown = ref(true);

        write({
            capture: () => snapshotRef(shown),
            apply: () => {
                shown.value = false;
            },
            url: '/acme/general/m1',
            method: 'delete',
            failure: 'Failed to delete the message',
        });
        destroy.mock.calls[0][1].onError({});

        expect(shown.value).toBe(true);
        expect(toastError).toHaveBeenCalledWith('Failed to delete the message');
    });

    it('takes a write nobody is waiting on out of the synchronous queue', () => {
        const { write } = useOptimisticWrite();

        write({
            capture: () => () => undefined,
            url: '/acme/general/star',
            method: 'patch',
            background: true,
            failure: 'Failed to update the channel',
        });

        expect(optionsOf(patch)).toMatchObject({
            async: true,
            preserveUrl: true,
        });
    });

    it('stays on the synchronous queue by default', () => {
        const { write } = useOptimisticWrite();

        write({
            capture: () => () => undefined,
            url: '/acme/general/star',
            failure: 'Failed to update the channel',
        });

        expect(optionsOf(post).async).toBeUndefined();
    });

    it('lets a write that must replace the page props opt out of preserveState', () => {
        const { write } = useOptimisticWrite();

        write({
            capture: () => () => undefined,
            url: '/acme/general/m1',
            method: 'patch',
            preserveState: false,
            failure: 'Your edit failed to save',
        });

        expect(optionsOf(patch).preserveState).toBe(false);
    });

    it('hands the response on to a write that reports its own success', () => {
        const { write } = useOptimisticWrite();
        const onSuccess = vi.fn();

        write({
            capture: () => () => undefined,
            url: '/acme/general/m1/forward',
            onSuccess,
            failure: 'Failed to forward the message',
        });
        optionsOf(post).onSuccess?.({ flash: { forwarded: null } });

        expect(onSuccess).toHaveBeenCalledWith({ flash: { forwarded: null } });
    });

    it('captures and restores without applying, for a mutation already made', () => {
        // vuedraggable has already written to the bound list by the time its
        // change handler fires, so that site restores by reseeding rather than
        // by applying anything of its own.
        const { write } = useOptimisticWrite();
        const reseed = vi.fn();

        write({
            capture: () => reseed,
            url: '/acme/general/placement',
            method: 'patch',
            failure: 'Failed to save the sidebar layout',
        });

        expect(reseed).not.toHaveBeenCalled();

        optionsOf(patch).onError({});

        expect(reseed).toHaveBeenCalledTimes(1);
    });
});
