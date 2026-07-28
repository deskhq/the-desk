import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const { post, patch, destroy, toastError, toastSuccess } = vi.hoisted(() => ({
    post: vi.fn(),
    patch: vi.fn(),
    destroy: vi.fn(),
    toastError: vi.fn(),
    toastSuccess: vi.fn(),
}));

/** The workspace props the undo paths read; seeded per test that needs them. */
const inertiaPage = reactive<{ props: Record<string, unknown> }>({ props: {} });

vi.mock('@inertiajs/vue3', () => ({
    router: { post, patch, delete: destroy },
    // Read by `useReminderUndo` (the reminder snapshot) and by the scheduled
    // message Undo, which both look the row up in the workspace props.
    usePage: () => inertiaPage,
}));
vi.mock('@/composables/useToast', () => {
    const toast = {
        error: toastError,
        success: toastSuccess,
        warning: vi.fn(),
        progress: vi.fn(),
    };

    return { useToast: () => toast };
});

import {
    clearMessageActionMocks,
    deleteOptionsOf,
    harness,
    message,
    optionsOf,
    pageFlashing,
    payloadOf,
} from '@/composables/useMessageActions.harness';
import type { ForwardTarget } from '@/types/forward';

describe('useMessageActions', () => {
    beforeEach(() => {
        clearMessageActionMocks();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    describe('forwardMessage', () => {
        const channelTarget = (
            id: string,
            name = 'elsewhere',
        ): ForwardTarget => ({
            kind: 'channel',
            id,
            name,
        });

        it('appends an optimistic copy when forwarding into the current channel', () => {
            const h = harness();
            const source = message({ id: 'src', body: 'original' });

            h.actions.forwardMessage(source, {
                target: channelTarget('chan-1'),
                note: 'passing this on',
            });

            expect(h.mainStream.pendingUuids.value).toHaveLength(1);
            expect(payloadOf(post)).toMatchObject({
                body: 'passing this on',
                target_channel_id: 'chan-1',
            });

            // A successful forward to the current channel stays silent — the echo
            // confirms it — so no toast fires.
            optionsOf(post).onSuccess?.();
            expect(toastSuccess).not.toHaveBeenCalled();

            // On error the optimistic copy is rolled back.
            optionsOf(post).onError?.();
            expect(h.mainStream.pendingUuids.value).toHaveLength(0);
            expect(toastError).toHaveBeenCalledOnce();
        });

        it('confirms a forward elsewhere with a toast and no optimistic row', () => {
            const h = harness();
            const source = message({ id: 'src' });

            h.actions.forwardMessage(source, {
                target: channelTarget('chan-2', 'random'),
                note: '',
            });

            expect(h.mainStream.pendingUuids.value).toHaveLength(0);
            expect(payloadOf(post)).toMatchObject({
                target_channel_id: 'chan-2',
            });

            optionsOf(post).onSuccess?.(pageFlashing({}));
            expect(toastSuccess).toHaveBeenCalledOnce();
        });

        it('offers Undo on a forward elsewhere, deleting the copy where it landed', () => {
            const h = harness();

            h.actions.forwardMessage(message({ id: 'src' }), {
                target: channelTarget('chan-2', 'random'),
                note: '',
            });

            optionsOf(post).onSuccess?.(
                pageFlashing({
                    forwarded: {
                        messageId: 'fwd-1',
                        channelSlug: 'random',
                    },
                }),
            );

            const action = toastSuccess.mock.calls.at(-1)?.[1]?.action;
            expect(action?.label).toBe('Undo');

            action.run();

            // Deleted in the channel it landed in, not the one being viewed —
            // the whole reason the response has to name that channel.
            expect(destroy).toHaveBeenCalledOnce();
            expect(destroy.mock.calls[0][0]).toContain(
                '/c/random/messages/fwd-1',
            );
        });

        it('stays on the source channel while undoing', () => {
            const h = harness();

            h.actions.forwardMessage(message({ id: 'src' }), {
                target: channelTarget('chan-2', 'random'),
                note: '',
            });
            optionsOf(post).onSuccess?.(
                pageFlashing({
                    forwarded: { messageId: 'fwd-1', channelSlug: 'random' },
                }),
            );
            toastSuccess.mock.calls.at(-1)?.[1]?.action.run();

            // The destroy route redirects to the deleted message's own channel,
            // which is the destination — the opposite of where the sender is.
            expect(deleteOptionsOf(0)).toMatchObject({
                preserveUrl: true,
                only: ['channels'],
            });
        });

        it('confirms without Undo when the response names no copy', () => {
            const h = harness();

            h.actions.forwardMessage(message({ id: 'src' }), {
                target: channelTarget('chan-2', 'random'),
                note: '',
            });

            optionsOf(post).onSuccess?.(
                pageFlashing({ forwarded: 'nonsense' }),
            );

            // A flash that arrives malformed degrades to the plain confirmation
            // rather than offering an Undo that would delete nothing.
            expect(toastSuccess.mock.calls.at(-1)?.[1]?.action).toBeUndefined();
        });

        it('reports a failed undo', () => {
            const h = harness();

            h.actions.forwardMessage(message({ id: 'src' }), {
                target: channelTarget('chan-2', 'random'),
                note: '',
            });
            optionsOf(post).onSuccess?.(
                pageFlashing({
                    forwarded: { messageId: 'fwd-1', channelSlug: 'random' },
                }),
            );
            toastSuccess.mock.calls.at(-1)?.[1]?.action.run();

            deleteOptionsOf(0).onError?.();

            expect(toastError).toHaveBeenCalledWith(
                'Failed to undo the forward',
            );
        });

        it('routes a person target to their DM', () => {
            const h = harness();

            h.actions.forwardMessage(message({ id: 'src' }), {
                target: { kind: 'user', id: 'user-3', name: 'Grace' },
                note: '',
            });

            expect(payloadOf(post)).toMatchObject({ target_user_id: 'user-3' });
        });
    });
});
