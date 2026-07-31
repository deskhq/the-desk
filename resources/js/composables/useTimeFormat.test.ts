import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const page = reactive({
    props: { auth: { user: { time_format: 'auto' } } },
});

const patch = vi.fn();
const toastError = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: {
        patch: (...args: unknown[]) => patch(...args),
    },
    usePage: () => page,
}));
vi.mock('@/composables/useToast', () => {
    const toast = {
        error: (...args: unknown[]) => toastError(...args),
        success: vi.fn(),
        warning: vi.fn(),
        progress: vi.fn(),
    };

    return { useToast: () => toast };
});

import { useTimeFormat } from '@/composables/useTimeFormat';
import { setTimeFormat, timeFormat } from '@/lib/clock';

beforeEach(() => {
    patch.mockReset();
    toastError.mockReset();
    page.props.auth.user.time_format = 'auto';
    setTimeFormat('auto');
});

afterEach(() => {
    setTimeFormat('auto');
});

describe('useTimeFormat', () => {
    it('reads the preference off the shared auth prop', () => {
        page.props.auth.user.time_format = '24h';

        expect(useTimeFormat().timeFormat.value).toBe('24h');
    });

    it('applies the new style before the write lands', () => {
        useTimeFormat().updateTimeFormat('24h');

        expect(timeFormat()).toBe('24h');
        expect(patch).toHaveBeenCalledWith(
            expect.any(String),
            { time_format: '24h' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    /**
     * The swapped style is the only thing driving the formatters, so a rejected
     * write has to put the previous one back — otherwise the whole interface
     * keeps rendering a clock the account never stored.
     */
    it('puts the previous style back when the write fails', () => {
        useTimeFormat().updateTimeFormat('24h');

        expect(timeFormat()).toBe('24h');

        const [, , options] = patch.mock.calls[0] as [
            string,
            unknown,
            { onError: () => void },
        ];
        options.onError();

        expect(timeFormat()).toBe('auto');
    });

    it('says the clock style failed to save, rather than silently reverting', () => {
        // The revert is visible — every rendered time of day changes back — so
        // without a sentence it reads as the switch simply not working.
        useTimeFormat().updateTimeFormat('24h');

        const [, , options] = patch.mock.calls[0] as [
            string,
            unknown,
            { onError: () => void },
        ];
        options.onError();

        expect(toastError).toHaveBeenCalledWith(
            'Failed to save the clock style',
        );
    });
});
