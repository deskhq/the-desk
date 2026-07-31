import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const { post, destroy, visit } = vi.hoisted(() => ({
    post: vi.fn(),
    destroy: vi.fn(),
    visit: vi.fn(),
}));

const page = reactive<{ props: Record<string, unknown> }>({ props: {} });

vi.mock('@inertiajs/vue3', () => ({
    router: { post, delete: destroy, visit },
    usePage: () => page,
}));

const { success, error } = vi.hoisted(() => ({
    success: vi.fn(),
    error: vi.fn(),
}));

vi.mock('@/composables/useToast', () => ({
    useToast: () => ({ success, error }),
}));

import { useReminders } from '@/composables/useReminders';
import type { ToastOptions } from '@/composables/useToast';
import { REMINDER_PROPS } from '@/lib/reminderReload';
import type { MessageReminder } from '@/types/messages';

/** A fired reminder, as the nudges and the Reminders destination carry one. */
function reminder(overrides: Partial<MessageReminder> = {}): MessageReminder {
    return {
        id: 'r1',
        messageId: 'm1',
        remindAt: '2026-03-01T09:00:00.000Z',
        teamSlug: 'acme',
        channelSlug: 'design',
        channelName: 'design',
        authorName: 'Ada',
        body: 'ship it',
        isDeleted: false,
        isAccessible: true,
        ...overrides,
    };
}

/** The visit options of the last `router.post`. */
function postOptions(): Record<string, unknown> {
    return post.mock.calls.at(-1)?.[2] as Record<string, unknown>;
}

/** The options of the toast raised by the last successful write. */
function confirmation(): ToastOptions {
    (postOptions().onSuccess as () => void)();

    return success.mock.calls.at(-1)?.[1] as ToastOptions;
}

describe('useReminders', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        page.props = { reminders: [] };
    });

    describe('setting one', () => {
        it('posts the chosen instant and refreshes only the reminder props', () => {
            useReminders().set('acme', 'm1', '2026-03-01T09:00:00.000Z');

            expect(post).toHaveBeenCalledOnce();
            expect(post.mock.calls[0][1]).toEqual({
                message_id: 'm1',
                remind_at: '2026-03-01T09:00:00.000Z',
            });
            expect(postOptions().only).toEqual(REMINDER_PROPS);
        });

        it('confirms with an Undo that puts back the time the message already had', () => {
            page.props = {
                reminders: [
                    {
                        id: 'r0',
                        messageId: 'm1',
                        remindAt: '2026-02-01T08:00:00.000Z',
                    },
                ],
            };

            useReminders().set('acme', 'm1', '2026-03-01T09:00:00.000Z');

            const toast = confirmation();

            expect(toast.action?.label).toBe('Undo');

            toast.action?.run();

            // The Undo is a second write of the *previous* time, not a delete:
            // setting a reminder on a message that already had one re-arms that
            // row rather than creating another.
            expect(post).toHaveBeenCalledTimes(2);
            expect(post.mock.calls[1][1]).toEqual({
                message_id: 'm1',
                remind_at: '2026-02-01T08:00:00.000Z',
            });
        });

        it('says so rather than leaving the caret waiting when the write fails', () => {
            useReminders().set('acme', 'm1', '2026-03-01T09:00:00.000Z');

            (postOptions().onError as () => void)();

            expect(error).toHaveBeenCalledWith('Failed to set the reminder');
        });
    });

    describe('snoozing a fired one', () => {
        beforeEach(() => {
            vi.useFakeTimers();
            vi.setSystemTime(new Date('2026-03-01T12:00:00.000Z'));
        });

        it('re-arms the same message twenty minutes out', () => {
            useReminders().snooze(reminder());

            expect(post.mock.calls[0][1]).toEqual({
                message_id: 'm1',
                remind_at: '2026-03-01T12:20:00.000Z',
            });

            vi.useRealTimers();
        });

        it('names the same span on the card as it posted', () => {
            // Interpolated from the one constant, so changing the span cannot
            // leave the confirmation promising a different one.
            useReminders().snooze(reminder());

            expect(confirmation().detail).toBe('For 20 minutes');

            vi.useRealTimers();
        });

        it('shares the set-reminder toast key, so only one Undo is ever on screen', () => {
            // A snooze and a set on the same message must not leave two Undos
            // up, only one of which still reverses anything.
            useReminders().set('acme', 'm1', '2026-03-01T09:00:00.000Z');
            const set = confirmation();

            useReminders().snooze(reminder());
            const snoozed = confirmation();

            expect(snoozed.key).toBe('reminder:m1');
            expect(snoozed.key).toBe(set.key);

            vi.useRealTimers();
        });

        it('undoes back to the time the reminder was fired for', () => {
            page.props = {
                reminders: [
                    {
                        id: 'r1',
                        messageId: 'm1',
                        remindAt: '2026-03-01T09:00:00.000Z',
                    },
                ],
            };

            useReminders().snooze(reminder());
            confirmation().action?.run();

            expect(post.mock.calls[1][1]).toEqual({
                message_id: 'm1',
                remind_at: '2026-03-01T09:00:00.000Z',
            });

            vi.useRealTimers();
        });
    });

    describe('clearing', () => {
        it('deletes the one row and refreshes only the reminder props', () => {
            useReminders().clear({ id: 'r1', teamSlug: 'acme' });

            expect(destroy).toHaveBeenCalledOnce();
            expect(destroy.mock.calls[0][0]).toContain('r1');
            expect(destroy.mock.calls[0][1]).toEqual(
                expect.objectContaining({ only: REMINDER_PROPS }),
            );
        });

        it('clears a whole workspace without naming a row', () => {
            useReminders().clearAll('acme');

            expect(destroy).toHaveBeenCalledOnce();
            expect(destroy.mock.calls[0][0]).toContain('acme');
            expect(destroy.mock.calls[0][0]).not.toContain('r1');
        });

        it('raises no toast, since the row leaving the list is the confirmation', () => {
            useReminders().clear({ id: 'r1', teamSlug: 'acme' });
            useReminders().clearAll('acme');

            expect(success).not.toHaveBeenCalled();
        });
    });

    describe('opening the reminded message', () => {
        it('clears the nudge before jumping, so the fresh page cannot re-raise it', () => {
            useReminders().open(reminder());

            expect(destroy).toHaveBeenCalledOnce();
            expect(visit).not.toHaveBeenCalled();

            const options = destroy.mock.calls[0][1] as {
                onFinish: () => void;
            };
            options.onFinish();

            expect(visit).toHaveBeenCalledOnce();
        });

        it('jumps to the message inside its own channel', () => {
            useReminders().open(
                reminder({ channelSlug: 'design', messageId: 'm7' }),
            );

            (destroy.mock.calls[0][1] as { onFinish: () => void }).onFinish();

            const target = visit.mock.calls[0][0] as string;

            expect(target).toContain('design');
            expect(target).toContain('m7');
        });
    });
});
