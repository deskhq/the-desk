import { beforeEach, describe, expect, it, vi } from 'vitest';

const { on, success, error, warning } = vi.hoisted(() => ({
    on: vi.fn(),
    success: vi.fn(),
    error: vi.fn(),
    warning: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({ router: { on } }));
vi.mock('@/composables/useToast', () => {
    const toast = { success, error, warning, progress: vi.fn() };

    return { useToast: () => toast };
});

import { initializeFlashToast } from '@/lib/flashToast';

/** Fire the `flash` event the way Inertia does, with the given payload. */
function flash(toast: unknown): void {
    initializeFlashToast();

    const handler = on.mock.calls.at(-1)?.[1] as (event: unknown) => void;

    handler({ detail: { flash: { toast } } });
}

describe('initializeFlashToast', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('raises a flashed success through the toast composable', () => {
        flash({ type: 'success', message: 'Photo updated everywhere.' });

        expect(success).toHaveBeenCalledWith('Photo updated everywhere.');
    });

    it('raises a flashed error through the toast composable', () => {
        flash({ type: 'error', message: 'An export is already generating' });

        expect(error).toHaveBeenCalledWith('An export is already generating');
    });

    it('raises a flashed warning through the toast composable', () => {
        flash({ type: 'warning', message: 'Your seat expires tomorrow' });

        expect(warning).toHaveBeenCalledWith('Your seat expires tomorrow');
    });

    it('stays quiet when the response carries no toast', () => {
        flash(undefined);

        expect(success).not.toHaveBeenCalled();
        expect(error).not.toHaveBeenCalled();
        expect(warning).not.toHaveBeenCalled();
    });

    it('ignores a tone it cannot speak rather than throwing at the user', () => {
        expect(() =>
            flash({ type: 'info', message: 'Heads up' }),
        ).not.toThrow();

        expect(success).not.toHaveBeenCalled();
        expect(error).not.toHaveBeenCalled();
        expect(warning).not.toHaveBeenCalled();
    });
});
