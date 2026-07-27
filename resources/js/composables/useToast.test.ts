import { beforeEach, describe, expect, it, vi } from 'vitest';

const { sonner } = vi.hoisted(() => ({
    sonner: {
        success: vi.fn(),
        error: vi.fn(),
        warning: vi.fn(),
        loading: vi.fn(),
    },
}));

vi.mock('vue-sonner', () => ({ toast: sonner }));

import { useToast } from '@/composables/useToast';

/** The options bag the composable handed to vue-sonner. */
function optionsOf(spy: {
    mock: { calls: unknown[][] };
}): Record<string, unknown> {
    return spy.mock.calls[0][1] as Record<string, unknown>;
}

describe('useToast', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    describe('tones', () => {
        it('speaks each tone through the matching sonner variant', () => {
            const toast = useToast();

            toast.success('Reminder set');
            toast.error('Could not save your status');
            toast.warning('Your connection is unstable');
            toast.progress('Uploading');

            expect(sonner.success).toHaveBeenCalledWith(
                'Reminder set',
                expect.anything(),
            );
            expect(sonner.error).toHaveBeenCalledWith(
                'Could not save your status',
                expect.anything(),
            );
            expect(sonner.warning).toHaveBeenCalledWith(
                'Your connection is unstable',
                expect.anything(),
            );
            expect(sonner.loading).toHaveBeenCalledWith(
                'Uploading',
                expect.anything(),
            );
        });
    });

    describe('the duration policy', () => {
        it('holds a bare confirmation for four seconds', () => {
            useToast().success('Reminder set');

            expect(optionsOf(sonner.success).duration).toBe(4_000);
        });

        it('holds a confirmation carrying an action for seven, so it can be taken', () => {
            useToast().success('Reminder set', {
                action: { label: 'Undo', run: vi.fn() },
            });

            expect(optionsOf(sonner.success).duration).toBe(7_000);
        });

        it('leaves an error up until it is dismissed, action or not', () => {
            const toast = useToast();

            toast.error('Message failed to send');
            toast.error('Message failed to send', {
                action: { label: 'Retry', run: vi.fn() },
            });

            expect(optionsOf(sonner.error).duration).toBe(Infinity);
            expect(
                (sonner.error.mock.calls[1][1] as { duration: number })
                    .duration,
            ).toBe(Infinity);
        });

        it('leaves work in progress up until it resolves', () => {
            useToast().progress('Uploading');

            expect(optionsOf(sonner.loading).duration).toBe(Infinity);
        });

        it('lets a call site override the policy', () => {
            useToast().success('Reminder set', { duration: 1_000 });

            expect(optionsOf(sonner.success).duration).toBe(1_000);
        });
    });

    describe('options', () => {
        it('renders the value that was set as the detail line', () => {
            useToast().success('Reminder set', { detail: 'Tomorrow, 9:00 AM' });

            expect(optionsOf(sonner.success).description).toBe(
                'Tomorrow, 9:00 AM',
            );
        });

        it('merges repeats under a key instead of queueing them', () => {
            const toast = useToast();

            toast.error('That file type is not allowed', { key: 'upload' });
            toast.error('That file type is not allowed', { key: 'upload' });

            expect(optionsOf(sonner.error).id).toBe('upload');
            expect((sonner.error.mock.calls[1][1] as { id: string }).id).toBe(
                'upload',
            );
        });

        it('leaves an unkeyed toast to queue on its own', () => {
            useToast().success('Reminder set');

            expect(optionsOf(sonner.success).id).toBeUndefined();
        });

        it('wires the action so taking it runs the call site handler', () => {
            const run = vi.fn();

            useToast().success('Reminder set', {
                action: { label: 'Undo', run },
            });

            const action = optionsOf(sonner.success).action as {
                label: string;
                onClick: () => void;
            };

            expect(action.label).toBe('Undo');

            action.onClick();

            expect(run).toHaveBeenCalledOnce();
        });

        it('omits the action slot when the toast carries none', () => {
            useToast().success('Reminder set');

            expect(optionsOf(sonner.success).action).toBeUndefined();
        });
    });
});
