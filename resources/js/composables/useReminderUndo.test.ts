import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const { post, destroy } = vi.hoisted(() => ({
    post: vi.fn(),
    destroy: vi.fn(),
}));

const page = reactive<{ props: Record<string, unknown> }>({ props: {} });

vi.mock('@inertiajs/vue3', () => ({
    router: { post, delete: destroy },
    usePage: () => page,
}));

import { useReminderUndo } from '@/composables/useReminderUndo';

/** Seed the viewer's pending reminders, the way the workspace props do. */
function seed(
    reminders: { id: string; messageId: string; remindAt: string }[],
) {
    page.props = { reminders };
}

describe('useReminderUndo', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        seed([]);
    });

    describe('the snapshot', () => {
        it('remembers the time a message was already set to remind at', () => {
            seed([
                { id: 'r1', messageId: 'm1', remindAt: '2026-01-01T09:00:00Z' },
            ]);

            expect(useReminderUndo().snapshot('m1')).toEqual({
                remindAt: '2026-01-01T09:00:00Z',
            });
        });

        it('is empty when the message had no reminder, so setting one is a create', () => {
            seed([
                {
                    id: 'r1',
                    messageId: 'other',
                    remindAt: '2026-01-01T09:00:00Z',
                },
            ]);

            expect(useReminderUndo().snapshot('m1')).toBeNull();
        });
    });

    describe('undoing', () => {
        it('puts the previous time back when the set overwrote one', () => {
            const { undo } = useReminderUndo();

            undo('acme', 'm1', { remindAt: '2026-01-01T09:00:00Z' });

            expect(post).toHaveBeenCalledOnce();
            expect(post.mock.calls[0][1]).toEqual({
                message_id: 'm1',
                remind_at: '2026-01-01T09:00:00Z',
            });
            expect(destroy).not.toHaveBeenCalled();
        });

        it('deletes the row the set created when there was nothing to put back', () => {
            seed([
                {
                    id: 'r-new',
                    messageId: 'm1',
                    remindAt: '2026-02-02T08:00:00Z',
                },
            ]);

            useReminderUndo().undo('acme', 'm1', null);

            expect(destroy).toHaveBeenCalledOnce();
            expect(destroy.mock.calls[0][0]).toContain('r-new');
            expect(post).not.toHaveBeenCalled();
        });

        it('does nothing rather than guessing when the created row cannot be found', () => {
            useReminderUndo().undo('acme', 'm1', null);

            expect(destroy).not.toHaveBeenCalled();
            expect(post).not.toHaveBeenCalled();
        });

        it('matches the row by message, since a user keeps at most one per message', () => {
            seed([
                {
                    id: 'r-other',
                    messageId: 'other',
                    remindAt: '2026-02-02T08:00:00Z',
                },
                {
                    id: 'r-new',
                    messageId: 'm1',
                    remindAt: '2026-02-02T08:00:00Z',
                },
            ]);

            useReminderUndo().undo('acme', 'm1', null);

            expect(destroy.mock.calls[0][0]).toContain('r-new');
        });
    });
});
