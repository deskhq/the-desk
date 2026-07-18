// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, h } from 'vue';

/** Mutable stand-in for the shared `demoMode` Inertia prop. */
const props = vi.hoisted(() => ({ demoMode: false as boolean }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props }),
}));

vi.mock('@lucide/vue', () => ({
    FlaskConical: { render: () => h('svg') },
}));

import DemoBanner from './DemoBanner.vue';

let app: App | null = null;

function mount() {
    const host = document.createElement('div');
    document.body.appendChild(host);

    app = createApp({ render: () => h(DemoBanner) });
    app.config.globalProperties.$t = (key: string) => key;
    app.mount(host);

    return host;
}

afterEach(() => {
    app?.unmount();
    app = null;
    props.demoMode = false;
    document.body.innerHTML = '';
});

describe('DemoBanner', () => {
    it('renders nothing off the demo', () => {
        props.demoMode = false;

        expect(mount().querySelector('[data-test="demo-banner"]')).toBeNull();
    });

    it('renders the demo notice on the demo', () => {
        props.demoMode = true;

        const banner = mount().querySelector('[data-test="demo-banner"]');

        expect(banner).not.toBeNull();
        expect(banner?.getAttribute('role')).toBe('status');
        expect(banner?.textContent).toContain('live demo');
    });
});
