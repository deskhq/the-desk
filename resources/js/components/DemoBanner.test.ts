// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, h, nextTick } from 'vue';

/** Mutable stand-in for the shared demo Inertia props. */
const props = vi.hoisted(() => ({
    demoMode: false as boolean,
    demoResetsAt: null as string | null,
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props }),
}));

vi.mock('@lucide/vue', () => ({
    FlaskConical: { render: () => h('svg') },
    RotateCcw: { render: () => h('svg') },
}));

import DemoBanner from './DemoBanner.vue';

let app: App | null = null;

/** Minimal `$t` that also resolves `:token` placeholders so counts render. */
function translate(
    key: string,
    replacements?: Record<string, unknown>,
): string {
    if (replacements === undefined) {
        return key;
    }

    return Object.entries(replacements).reduce(
        (line, [token, value]) => line.replaceAll(`:${token}`, String(value)),
        key,
    );
}

function mount() {
    const host = document.createElement('div');
    document.body.appendChild(host);

    app = createApp({ render: () => h(DemoBanner) });
    app.config.globalProperties.$t = translate;
    app.mount(host);

    return host;
}

beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(() => {
    app?.unmount();
    app = null;
    props.demoMode = false;
    props.demoResetsAt = null;
    document.body.innerHTML = '';
    vi.useRealTimers();
});

describe('DemoBanner', () => {
    it('renders nothing off the demo', () => {
        props.demoMode = false;

        expect(mount().querySelector('[data-test="demo-banner"]')).toBeNull();
    });

    it('renders the demo notice with bold "live demo" on the demo', () => {
        props.demoMode = true;

        const banner = mount().querySelector('[data-test="demo-banner"]');

        expect(banner).not.toBeNull();
        expect(banner?.getAttribute('role')).toBe('status');
        expect(banner?.textContent).toContain('live demo');
        expect(banner?.querySelector('strong')?.textContent).toBe('live demo');
    });

    it('shows the minutes until the next hourly reset', () => {
        props.demoMode = true;
        props.demoResetsAt = '2026-07-18T17:00:00+00:00';
        vi.setSystemTime(new Date('2026-07-18T16:18:00+00:00'));

        const chip = mount().querySelector(
            '[data-test="demo-reset-countdown"]',
        );

        expect(chip?.textContent).toContain('Resets in 42 min');
        // Muted from the role="status" live region so the tick isn't announced every minute.
        expect(chip?.getAttribute('aria-live')).toBe('off');
    });

    it('ticks the countdown down as time passes', async () => {
        props.demoMode = true;
        props.demoResetsAt = '2026-07-18T17:00:00+00:00';
        vi.setSystemTime(new Date('2026-07-18T16:18:00+00:00'));

        const host = mount();

        // Advancing fake timers also advances the clock, so 41 ticks lands at 16:59.
        vi.advanceTimersByTime(41 * 60_000);
        await nextTick();

        expect(
            host.querySelector('[data-test="demo-reset-countdown"]')
                ?.textContent,
        ).toContain('Resets in 1 min');
    });

    it('rolls a stale timestamp forward across the reset boundary', async () => {
        props.demoMode = true;
        props.demoResetsAt = '2026-07-18T17:00:00+00:00';
        vi.setSystemTime(new Date('2026-07-18T16:59:00+00:00'));

        const host = mount();

        // Two ticks push the clock to 17:01, past the shared 17:00 reset.
        vi.advanceTimersByTime(2 * 60_000);
        await nextTick();

        expect(
            host.querySelector('[data-test="demo-reset-countdown"]')
                ?.textContent,
        ).toContain('Resets in 59 min');
    });

    it('hides the countdown chip when no reset timestamp is shared', () => {
        props.demoMode = true;
        props.demoResetsAt = null;

        const banner = mount().querySelector('[data-test="demo-banner"]');

        expect(banner).not.toBeNull();
        expect(
            banner?.querySelector('[data-test="demo-reset-countdown"]'),
        ).toBeNull();
    });
});
