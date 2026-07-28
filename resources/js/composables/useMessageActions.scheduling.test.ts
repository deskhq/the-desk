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
    optionsOf,
    payloadOf,
} from '@/composables/useMessageActions.harness';

describe('useMessageActions', () => {
    beforeEach(() => {
        clearMessageActionMocks();
        // Every undo path here reads the workspace props, and each test seeds
        // the ones it needs; without this they would run against whatever the
        // test before them left behind.
        inertiaPage.props = {};
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    describe('scheduleMessage', () => {
        it('posts to the scheduled surface and toasts on success/error', () => {
            const h = harness();

            h.actions.scheduleMessage('later', [], '2024-06-01T09:00:00Z');

            expect(h.cancelDraft).toHaveBeenCalledOnce();
            expect(h.cancelReply).toHaveBeenCalledOnce();
            expect(optionsOf(post).only).toEqual([
                'scheduledMessages',
                'channels',
            ]);
            expect(payloadOf(post)).toMatchObject({
                body: 'later',
                send_at: '2024-06-01T09:00:00Z',
            });

            optionsOf(post).onSuccess?.();
            expect(toastSuccess).toHaveBeenCalledOnce();

            optionsOf(post).onError?.();
            expect(toastError).toHaveBeenCalledOnce();
        });
    });

    describe('updateScheduled', () => {
        it('patches the scheduled message and toasts on error', () => {
            const h = harness();

            h.actions.updateScheduled({
                id: 's1',
                body: 'edited',
                sendAt: '2024-06-02T09:00:00Z',
            });

            expect(optionsOf(patch).only).toEqual(['scheduledMessages']);
            expect(payloadOf(patch)).toEqual({
                body: 'edited',
                send_at: '2024-06-02T09:00:00Z',
            });

            optionsOf(patch).onError?.();
            expect(toastError).toHaveBeenCalledOnce();
        });
    });

    describe('cancelScheduled', () => {
        it('deletes the scheduled message and toasts on success/error', () => {
            const h = harness();

            h.actions.cancelScheduled('s1');

            expect(deleteOptionsOf().only).toEqual(['scheduledMessages']);

            deleteOptionsOf().onSuccess?.();
            expect(toastSuccess).toHaveBeenCalledOnce();

            deleteOptionsOf().onError?.();
            expect(toastError).toHaveBeenCalledOnce();
        });
    });

    describe('undo', () => {
        it('offers an Undo on a scheduled message that cancels the row it just created, found by its client uuid', () => {
            const h = harness();

            h.actions.scheduleMessage('later', [], '2024-06-01T09:00:00Z');

            const clientUuid = payloadOf(post).client_uuid as string;

            // The reload the success arrives with brings the created row back.
            inertiaPage.props = {
                scheduledMessages: [
                    { id: 'other', clientUuid: 'not-this-one' },
                    { id: 'sched-1', clientUuid },
                ],
            };

            optionsOf(post).onSuccess?.();

            const action = (
                toastSuccess.mock.calls[0][1] as {
                    action: { label: string; run: () => void };
                }
            ).action;

            expect(action.label).toBe('Undo');

            action.run();

            expect(destroy.mock.calls[0][0]).toContain('sched-1');
        });

        it('leaves the schedule alone when Undo can no longer find the row', () => {
            const h = harness();

            h.actions.scheduleMessage('later', [], '2024-06-01T09:00:00Z');
            inertiaPage.props = { scheduledMessages: [] };
            optionsOf(post).onSuccess?.();

            (
                toastSuccess.mock.calls[0][1] as { action: { run: () => void } }
            ).action.run();

            expect(destroy).not.toHaveBeenCalled();
        });

        it('offers an Undo on a reminder that puts back the time the message was already set to', () => {
            inertiaPage.props = {
                reminders: [
                    {
                        id: 'r1',
                        messageId: 'm9',
                        remindAt: '2024-01-01T07:00:00Z',
                    },
                ],
            };

            const h = harness();

            h.actions.setReminder('m9', '2024-06-03T09:00:00Z');
            optionsOf(post).onSuccess?.();

            (
                toastSuccess.mock.calls[0][1] as { action: { run: () => void } }
            ).action.run();

            // A re-arm, not a delete: setting a reminder on a message that
            // already had one overwrites it, so Undo restores the old time.
            expect(destroy).not.toHaveBeenCalled();
            expect(payloadOf(post, 1)).toEqual({
                message_id: 'm9',
                remind_at: '2024-01-01T07:00:00Z',
            });
        });

        it('offers an Undo on a reminder that clears it when the set created one', () => {
            inertiaPage.props = { reminders: [] };

            const h = harness();

            h.actions.setReminder('m9', '2024-06-03T09:00:00Z');

            inertiaPage.props = {
                reminders: [
                    {
                        id: 'r-new',
                        messageId: 'm9',
                        remindAt: '2024-06-03T09:00:00Z',
                    },
                ],
            };
            optionsOf(post).onSuccess?.();

            (
                toastSuccess.mock.calls[0][1] as { action: { run: () => void } }
            ).action.run();

            expect(destroy.mock.calls[0][0]).toContain('r-new');
        });
    });

    describe('setReminder', () => {
        it('posts the reminder and toasts on success/error', () => {
            const h = harness();

            h.actions.setReminder('m9', '2024-06-03T09:00:00Z');

            expect(optionsOf(post).only).toEqual([
                'reminders',
                'firedReminders',
            ]);
            expect(payloadOf(post)).toEqual({
                message_id: 'm9',
                remind_at: '2024-06-03T09:00:00Z',
            });

            optionsOf(post).onSuccess?.();
            expect(toastSuccess).toHaveBeenCalledOnce();

            optionsOf(post).onError?.();
            expect(toastError).toHaveBeenCalledOnce();
        });
    });
});
