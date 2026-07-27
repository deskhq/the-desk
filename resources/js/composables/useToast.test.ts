import { beforeEach, describe, expect, it, vi } from 'vitest';

const { sonner } = vi.hoisted(() => ({ sonner: { custom: vi.fn() } }));

vi.mock('vue-sonner', () => ({ toast: sonner }));

import ToastCard from '@/components/ToastCard.vue';
import { useToast } from '@/composables/useToast';

/** The options bag the composable handed to sonner on its nth call. */
function optionsOf(call = 0): Record<string, unknown> {
    return sonner.custom.mock.calls[call][1] as Record<string, unknown>;
}

/** The props the composable handed the toast card on its nth call. */
function cardPropsOf(call = 0): Record<string, unknown> {
    return optionsOf(call).componentProps as Record<string, unknown>;
}

describe('useToast', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    describe('the renderer', () => {
        it('draws every toast with our own card, not sonner’s', () => {
            useToast().success('Reminder set');

            expect(sonner.custom).toHaveBeenCalledWith(
                ToastCard,
                expect.objectContaining({ unstyled: true }),
            );
        });

        it('hands the card the tone so the glyph and copy both carry it', () => {
            const toast = useToast();

            toast.success('Reminder set');
            toast.error('Could not save your status');
            toast.warning('Microphone unavailable');
            toast.progress('Uploading');

            expect(cardPropsOf(0).tone).toBe('success');
            expect(cardPropsOf(1).tone).toBe('error');
            expect(cardPropsOf(2).tone).toBe('warning');
            expect(cardPropsOf(3).tone).toBe('progress');
        });

        it('hands the card its title and detail line', () => {
            useToast().success('Reminder set', { detail: 'Tomorrow, 9:00 AM' });

            expect(cardPropsOf().title).toBe('Reminder set');
            expect(cardPropsOf().detail).toBe('Tomorrow, 9:00 AM');
        });
    });

    describe('the duration policy', () => {
        it('holds a bare confirmation for four seconds', () => {
            useToast().success('Reminder set');

            expect(optionsOf().duration).toBe(4_000);
        });

        it('holds a confirmation carrying an action for seven, so it can be taken', () => {
            useToast().success('Reminder set', {
                action: { label: 'Undo', run: vi.fn() },
            });

            expect(optionsOf().duration).toBe(7_000);
        });

        it('leaves an error up until it is dismissed, action or not', () => {
            const toast = useToast();

            toast.error('Message failed to send');
            toast.error('Message failed to send', {
                action: { label: 'Retry', run: vi.fn() },
            });

            expect(optionsOf(0).duration).toBe(Infinity);
            expect(optionsOf(1).duration).toBe(Infinity);
        });

        it('leaves work in progress up until it resolves', () => {
            useToast().progress('Uploading');

            expect(optionsOf().duration).toBe(Infinity);
        });

        it('lets a call site override the policy', () => {
            useToast().success('Reminder set', { duration: 1_000 });

            expect(optionsOf().duration).toBe(1_000);
        });

        it('tells the card the same duration its drain has to count', () => {
            useToast().success('Reminder set');

            expect(cardPropsOf().duration).toBe(optionsOf().duration);
        });
    });

    describe('options', () => {
        it('merges repeats under a key instead of queueing them', () => {
            const toast = useToast();

            toast.error('That file type is not allowed', { key: 'upload' });
            toast.error('That file type is not allowed', { key: 'upload' });

            expect(optionsOf(0).id).toBe('upload');
            expect(optionsOf(1).id).toBe('upload');
        });

        it('leaves an unkeyed toast to queue on its own', () => {
            useToast().success('Reminder set');

            expect(optionsOf().id).toBeUndefined();
        });

        it('wires the action so taking it runs the call site handler', () => {
            const run = vi.fn();

            useToast().success('Reminder set', {
                action: { label: 'Undo', run },
            });

            const action = cardPropsOf().action as {
                label: string;
                run: () => void;
            };

            expect(action.label).toBe('Undo');

            action.run();

            expect(run).toHaveBeenCalledOnce();
        });

        it('omits the action slot when the toast carries none', () => {
            useToast().success('Reminder set');

            expect(cardPropsOf().action).toBeUndefined();
        });
    });
});
