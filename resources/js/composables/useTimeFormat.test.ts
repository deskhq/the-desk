import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const page = reactive({
    props: { auth: { user: { time_format: 'auto' } } },
});

const patch = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: {
        patch: (...args: unknown[]) => patch(...args),
    },
    usePage: () => page,
}));

import { useTimeFormat } from '@/composables/useTimeFormat';
import { setTimeFormat, timeFormat } from '@/lib/clock';

beforeEach(() => {
    patch.mockReset();
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
});
