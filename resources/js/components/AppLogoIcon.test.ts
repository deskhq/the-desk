// @vitest-environment jsdom
import { afterEach, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, h } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const branding = { logo: null as string | null, attribution: true };

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { name: 'Acme Chat', branding } }),
}));

let app: App | null = null;

function mount(className?: string): HTMLElement {
    const host = document.createElement('div');
    document.body.append(host);

    app = createApp({
        render: () => h(AppLogoIcon, { className }),
    });
    app.mount(host);

    return host;
}

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
    branding.logo = null;
});

it('draws the shipped inline mark when the instance is not rebranded', () => {
    const host = mount();

    const svg = host.querySelector('svg');

    expect(svg).not.toBeNull();
    expect(host.querySelector('img')).toBeNull();
    // The lower planes ride on `currentColor` so the mark follows its surface.
    expect(svg?.innerHTML).toContain('currentColor');
});

it("renders the operator's mark as an image once one is supplied", () => {
    branding.logo = '/branding/logo';

    const host = mount();

    const image = host.querySelector('img');

    expect(image?.getAttribute('src')).toBe('/branding/logo');
    expect(host.querySelector('svg')).toBeNull();
});

it('leaves the operator mark decorative, as the inline one is', () => {
    branding.logo = '/branding/logo';

    // Every call site pairs the mark with the visible instance name, so an
    // alt text here would only make a screen reader say it twice.
    expect(mount().querySelector('img')?.getAttribute('alt')).toBe('');
});

it('carries the caller class onto whichever mark it draws', () => {
    expect(mount('size-6')?.querySelector('svg')?.getAttribute('class')).toBe(
        'size-6',
    );

    app?.unmount();
    document.body.innerHTML = '';
    branding.logo = '/branding/logo';

    expect(mount('size-6')?.querySelector('img')?.getAttribute('class')).toBe(
        'size-6',
    );
});
