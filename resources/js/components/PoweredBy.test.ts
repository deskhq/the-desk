// @vitest-environment jsdom
import { afterEach, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, h } from 'vue';
import PoweredBy from '@/components/PoweredBy.vue';

const branding = { logo: null as string | null, attribution: true };

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { name: 'Acme Chat', branding } }),
}));

let app: App | null = null;

function mount(): HTMLElement {
    const host = document.createElement('div');
    document.body.append(host);

    app = createApp({ render: () => h(PoweredBy) });
    app.config.globalProperties.$t = (key: string): string => key;
    app.mount(host);

    return host;
}

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
    branding.attribution = true;
});

it('credits the project by default, linking out to it', () => {
    const link = mount().querySelector('a');

    expect(link?.textContent?.trim()).toBe('Powered by The Desk');
    expect(link?.getAttribute('href')).toBe('https://thedeskhq.app');
    expect(link?.getAttribute('rel')).toContain('noopener');
});

it('disappears entirely once the operator turns attribution off', () => {
    branding.attribution = false;

    expect(mount().querySelector('a')).toBeNull();
});

it('credits the project rather than the instance, which may be renamed', () => {
    expect(mount().textContent).not.toContain('Acme Chat');
});
