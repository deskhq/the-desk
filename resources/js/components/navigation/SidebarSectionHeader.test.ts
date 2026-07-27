// @vitest-environment jsdom
import { afterEach, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, h } from 'vue';

vi.mock('@lucide/vue', () => ({ ChevronRight: { render: () => h('svg') } }));

import SidebarSectionHeader from './SidebarSectionHeader.vue';

let app: App | null = null;

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

function mountHeader(name: string, collapsed = false) {
    const host = document.createElement('div');
    document.body.append(host);

    const toggle = vi.fn();

    app = createApp({
        render: () =>
            h(SidebarSectionHeader, {
                name,
                label: 'Starred',
                collapsed,
                onToggle: toggle,
            }),
    });
    app.mount(host);

    return { host, toggle, button: host.querySelector('button')! };
}

it('keeps the per-section test selector the browser suite reaches it by', () => {
    const { host } = mountHeader('starred');

    expect(
        host.querySelector('[data-test="section-toggle-starred"]'),
    ).not.toBeNull();
});

it('names its expanded state for assistive tech', () => {
    expect(mountHeader('channels').button.getAttribute('aria-expanded')).toBe(
        'true',
    );
});

it('names its collapsed state for assistive tech', () => {
    expect(
        mountHeader('channels', true).button.getAttribute('aria-expanded'),
    ).toBe('false');
});

it('turns the chevron down while the group is open', () => {
    expect(
        mountHeader('direct').host.querySelector('svg')?.getAttribute('class'),
    ).toContain('rotate-90');
});

it('leaves the chevron pointing right while the group is collapsed', () => {
    expect(
        mountHeader('direct', true)
            .host.querySelector('svg')
            ?.getAttribute('class'),
    ).not.toContain('rotate-90');
});

it('renders the label it was handed, already translated', () => {
    expect(mountHeader('starred').host.textContent).toContain('Starred');
});

it('asks its host to collapse the group rather than doing it itself', () => {
    const { button, toggle } = mountHeader('starred');

    button.click();

    expect(toggle).toHaveBeenCalledTimes(1);
});
