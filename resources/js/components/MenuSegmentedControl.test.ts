// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h, nextTick } from 'vue';
import MenuSegmentedControl from './MenuSegmentedControl.vue';

const Dot = defineComponent({ render: () => h('svg') });

const OPTIONS = [
    { value: 'light', label: 'Light', icon: Dot },
    { value: 'dark', label: 'Dark', icon: Dot },
    { value: 'system', label: 'System', icon: Dot },
];

let app: App | null = null;

function mount(modelValue: string, props: Record<string, unknown> = {}) {
    const updates: string[] = [];
    const host = document.createElement('div');
    document.body.appendChild(host);

    app = createApp({
        render: () =>
            h(MenuSegmentedControl, {
                modelValue,
                options: OPTIONS,
                ariaLabel: 'Theme',
                'onUpdate:modelValue': (value: string) => updates.push(value),
                ...props,
            }),
    });
    app.mount(host);

    const radios = Array.from(
        host.querySelectorAll<HTMLButtonElement>(
            '[role="menuitemradio"], [role="radio"]',
        ),
    );

    return { host, radios, updates };
}

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

describe('MenuSegmentedControl', () => {
    it('renders a labelled group with one menuitemradio per option, the current one checked', () => {
        const { host, radios } = mount('dark');

        const group = host.querySelector('[role="group"]');
        expect(group?.getAttribute('aria-label')).toBe('Theme');
        expect(radios).toHaveLength(3);
        expect(radios.map((r) => r.getAttribute('aria-checked'))).toEqual([
            'false',
            'true',
            'false',
        ]);
        // Each icon-only segment is named and carries a tooltip.
        expect(radios.map((r) => r.getAttribute('aria-label'))).toEqual([
            'Light',
            'Dark',
            'System',
        ]);
        expect(radios.map((r) => r.getAttribute('title'))).toEqual([
            'Light',
            'Dark',
            'System',
        ]);
    });

    it('gives only the checked radio a tabindex of 0 (roving tabindex)', () => {
        const { radios } = mount('dark');

        expect(radios.map((r) => r.getAttribute('tabindex'))).toEqual([
            '-1',
            '0',
            '-1',
        ]);
    });

    it('emits the new value on click but stays silent when the current one is re-clicked', () => {
        const { radios, updates } = mount('dark');

        radios[2].click();
        radios[1].click();

        expect(updates).toEqual(['system']);
    });

    it('moves selection with the arrow keys, wrapping at the ends', async () => {
        const { radios, updates } = mount('dark');

        radios[1].dispatchEvent(
            new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }),
        );
        await nextTick();
        radios[0].dispatchEvent(
            new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }),
        );

        expect(updates).toEqual(['system', 'system']);
    });

    it('toggles with Space/Enter without letting the event bubble to the dropdown', () => {
        const bubbled: string[] = [];
        const { host, radios } = mount('dark');
        host.addEventListener('keydown', (e) => bubbled.push(e.key));

        const event = new KeyboardEvent('keydown', {
            key: 'Enter',
            bubbles: true,
            cancelable: true,
        });
        radios[1].dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(bubbled).toEqual([]);
    });

    it('slides the thumb to the active segment', () => {
        const { host } = mount('system');

        const thumb = host.querySelector<HTMLElement>('[data-slot="thumb"]');
        expect(thumb?.style.transform).toBe('translateX(62px)');
    });

    it('renders the radio-group pattern outside a menu, for surfaces like the mobile sheet', () => {
        const { host, radios } = mount('dark', { standalone: true });

        const group = host.querySelector('[role="radiogroup"]');
        expect(group?.getAttribute('aria-label')).toBe('Theme');
        expect(host.querySelector('[role="menuitemradio"]')).toBeNull();
        expect(radios).toHaveLength(3);
        expect(radios.every((r) => r.getAttribute('role') === 'radio')).toBe(
            true,
        );
        expect(radios.map((r) => r.getAttribute('aria-checked'))).toEqual([
            'false',
            'true',
            'false',
        ]);
    });
});
