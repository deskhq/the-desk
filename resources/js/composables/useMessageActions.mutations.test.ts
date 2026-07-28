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
    payloadOf,
} from '@/composables/useMessageActions.harness';

describe('useMessageActions', () => {
    beforeEach(() => {
        clearMessageActionMocks();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    describe('editMessage', () => {
        it('patches optimistically and rolls back to the prior copy on error', () => {
            const original = message({ body: 'before' });
            const h = harness({ serverMain: [original] });

            h.actions.editMessage(original, 'after');
            expect(
                h.mainStream.displayMessages.value.find((m) => m.id === 'm1')
                    ?.body,
            ).toBe('after');
            expect(payloadOf(patch)).toEqual({ body: 'after' });

            optionsOf(patch).onError?.();
            expect(
                h.mainStream.displayMessages.value.find((m) => m.id === 'm1')
                    ?.body,
            ).toBe('before');
            expect(toastError).toHaveBeenCalledOnce();
        });
    });

    describe('deleteMessage', () => {
        it('shows a tombstone optimistically and restores it on error', () => {
            const original = message({ body: 'delete me' });
            const h = harness({ serverMain: [original] });

            h.actions.deleteMessage(original);
            expect(
                h.mainStream.displayMessages.value.find((m) => m.id === 'm1')
                    ?.isDeleted,
            ).toBe(true);

            deleteOptionsOf().onError?.();
            expect(
                h.mainStream.displayMessages.value.find((m) => m.id === 'm1')
                    ?.isDeleted,
            ).toBe(false);
            expect(toastError).toHaveBeenCalledOnce();
        });
    });

    describe('reactToMessage', () => {
        it('patches reactions optimistically and rolls back on error', () => {
            const original = message();
            const h = harness({ serverMain: [original] });

            h.actions.reactToMessage(original, '👍');
            expect(
                h.mainStream.displayMessages.value.find((m) => m.id === 'm1')
                    ?.reactions,
            ).toHaveLength(1);
            expect(optionsOf(post).only).toEqual(['channels']);

            optionsOf(post).onError?.();
            expect(
                h.mainStream.displayMessages.value.find((m) => m.id === 'm1')
                    ?.reactions,
            ).toHaveLength(0);
            expect(toastError).toHaveBeenCalledOnce();
        });
    });

    describe('sendThreadReply', () => {
        it('does nothing when no thread is open', () => {
            const h = harness({ activeThreadRootId: null });

            h.actions.sendThreadReply('into the void', []);

            expect(post).not.toHaveBeenCalled();
        });

        it('adds a pending reply to the thread and marks the root followed', () => {
            const root = message({ id: 'root-1' });
            const h = harness({
                serverMain: [root],
                activeThreadRootId: 'root-1',
            });

            h.actions.sendThreadReply('a reply', []);

            expect(h.threadStream.pendingUuids.value).toHaveLength(1);
            // The root's dot clears in the main timeline.
            expect(
                h.mainStream.displayMessages.value.find(
                    (m) => m.id === 'root-1',
                )?.threadFollowed,
            ).toBe(true);
            expect(payloadOf(post)).toMatchObject({
                thread_root_id: 'root-1',
                sent_to_channel: false,
            });
        });

        it('also echoes into the main timeline when sent to channel', () => {
            const root = message({ id: 'root-1' });
            const h = harness({
                serverMain: [root],
                activeThreadRootId: 'root-1',
            });

            h.actions.sendThreadReply('shared reply', [], true);

            expect(h.threadStream.pendingUuids.value).toHaveLength(1);
            expect(h.mainStream.pendingUuids.value).toHaveLength(1);

            optionsOf(post).onError?.();
            expect(h.threadStream.pendingUuids.value).toHaveLength(0);
            expect(h.mainStream.pendingUuids.value).toHaveLength(0);
            expect(toastError).toHaveBeenCalledOnce();
        });
    });
});
