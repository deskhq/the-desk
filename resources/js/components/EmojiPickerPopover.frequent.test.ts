// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h, ref } from 'vue';
import { translate } from '@/lib/i18n';

/** Mutable stand-in for the shared Inertia props the strips read. */
const props = vi.hoisted(() => ({
    frequentEmojis: [] as string[],
    customEmojis: {} as Record<string, string>,
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props }),
}));

// Flatten Reka's popover to passthroughs so the panel renders inline instead of
// through a portal that only mounts once the trigger is clicked.
vi.mock('reka-ui', () => {
    const pass = (name: string) =>
        defineComponent({
            name,
            setup:
                (_p, { slots }) =>
                () =>
                    h('div', slots.default?.()),
        });

    return {
        PopoverContent: pass('PopoverContent'),
        PopoverPortal: pass('PopoverPortal'),
        PopoverRoot: pass('PopoverRoot'),
        PopoverTrigger: pass('PopoverTrigger'),
    };
});

vi.mock('@/components/ui/tooltip', () => {
    const pass = (name: string) =>
        defineComponent({
            name,
            setup:
                (_p, { slots }) =>
                () =>
                    h('div', slots.default?.()),
        });

    return {
        Tooltip: pass('Tooltip'),
        TooltipContent: pass('TooltipContent'),
        TooltipTrigger: pass('TooltipTrigger'),
    };
});

// The picker library reaches for indexedDB at module load; the strips under
// test never need it, so stand the grid down to an empty component.
vi.mock('vue3-emoji-picker/css', () => ({}));
vi.mock('vue3-emoji-picker', () => ({
    default: defineComponent({
        name: 'EmojiPicker',
        setup: () => () => h('div'),
    }),
}));

vi.mock('@/composables/useAppearance', () => ({
    useAppearance: () => ({ resolvedAppearance: ref('light') }),
}));

vi.mock('@/composables/useEmojiPickerA11y', () => ({
    useEmojiPickerA11y: () => undefined,
}));

import EmojiPickerPopover from './EmojiPickerPopover.vue';

let app: App | null = null;

function mount(): { host: HTMLElement; selected: string[] } {
    const selected: string[] = [];
    const host = document.createElement('div');
    document.body.appendChild(host);

    app = createApp({
        render: () =>
            h(
                EmojiPickerPopover,
                { onSelect: (emoji: string) => selected.push(emoji) },
                { default: () => h('button', 'open') },
            ),
    });
    app.config.globalProperties.$t = translate;
    app.mount(host);

    return { host, selected };
}

function cells(host: HTMLElement): HTMLElement[] {
    return [
        ...host.querySelectorAll<HTMLElement>(
            '[data-test="frequent-emoji-option"]',
        ),
    ];
}

beforeEach(() => {
    props.frequentEmojis = ['👍', '❤️', ':shipit:'];
    props.customEmojis = { shipit: 'https://desk.test/shipit.png' };
});

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

describe('EmojiPickerPopover frequently-used strip', () => {
    it('renders the strip above the custom strip', () => {
        const { host } = mount();
        const strips = [
            ...host.querySelectorAll('[data-test$="-emoji-strip"]'),
        ].map((node) => node.getAttribute('data-test'));

        expect(strips).toEqual(['frequent-emoji-strip', 'custom-emoji-strip']);
        expect(
            host.querySelector('[data-test="frequent-emoji-strip"]')
                ?.textContent,
        ).toContain('Frequently used');
    });

    it('renders native glyphs as text and shortcodes as their image', () => {
        const { host } = mount();

        expect(cells(host)).toHaveLength(3);
        expect(cells(host)[0].textContent?.trim()).toBe('👍');
        expect(cells(host)[2].querySelector('img')?.getAttribute('src')).toBe(
            'https://desk.test/shipit.png',
        );
    });

    it('emits the picked emoji exactly like any other cell', () => {
        const { host, selected } = mount();

        cells(host)[0].click();
        cells(host)[2].click();

        expect(selected).toEqual(['👍', ':shipit:']);
    });

    it('omits the strip when the ranking is empty', () => {
        props.frequentEmojis = [];

        const { host } = mount();

        expect(
            host.querySelector('[data-test="frequent-emoji-strip"]'),
        ).toBeNull();
    });
});
