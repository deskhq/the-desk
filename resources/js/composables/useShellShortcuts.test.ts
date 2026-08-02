// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, h, reactive } from 'vue';

const { visit } = vi.hoisted(() => ({ visit: vi.fn() }));

const page = reactive<{ props: Record<string, unknown> }>({ props: {} });

vi.mock('@inertiajs/vue3', () => ({
    router: { visit },
    usePage: () => page,
}));

const focusNotificationRail = vi.hoisted(() => vi.fn());

vi.mock('@/composables/useShellFocus', () => ({
    useShellFocus: () => ({ focusNotificationRail }),
}));

import { useDialog } from '@/composables/useDialog';
import { useShellShortcuts } from '@/composables/useShellShortcuts';

let app: App | null = null;

/** A workspace with three channels, the third of them open. */
function seed(activeSlug: string | null = 'design'): void {
    page.props = {
        currentTeam: { id: 't1', slug: 'acme' },
        channels: [{ slug: 'general' }, { slug: 'random' }, { slug: 'design' }],
        channel: activeSlug ? { slug: activeSlug } : undefined,
    };
}

/** Mount the shortcuts on a bare component — never the shell itself. */
function bindShortcuts(): void {
    app = createApp({
        setup: () => {
            useShellShortcuts();

            return () => h('div');
        },
    });
    app.mount(document.createElement('div'));
}

/** Press a key on `window`, the way the app-wide listener hears it. */
function press(key: string, modifiers: Partial<KeyboardEventInit> = {}): void {
    window.dispatchEvent(
        new KeyboardEvent('keydown', { key, bubbles: true, ...modifiers }),
    );
}

describe('useShellShortcuts', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        seed();
        useDialog('switcher').close();
        useDialog('shortcuts').close();
        bindShortcuts();
    });

    afterEach(() => {
        app?.unmount();
        app = null;
    });

    it('toggles the jump-to palette rather than only opening it', () => {
        press('k', { metaKey: true });
        expect(useDialog('switcher').isOpen.value).toBe(true);

        press('k', { metaKey: true });
        expect(useDialog('switcher').isOpen.value).toBe(false);
    });

    it('toggles the shortcuts help', () => {
        press('?', { shiftKey: true });

        expect(useDialog('shortcuts').isOpen.value).toBe(true);
    });

    it('hands the caret to the notification rail', () => {
        press('F6');

        expect(focusNotificationRail).toHaveBeenCalledOnce();
    });

    describe('walking the channel list', () => {
        it('steps to the next channel along the sidebar order', () => {
            seed('random');

            press('ArrowDown', { altKey: true });

            expect(visit).toHaveBeenCalledOnce();
            expect(visit.mock.calls[0][0]).toContain('design');
        });

        it('steps back to the previous one', () => {
            seed('random');

            press('ArrowUp', { altKey: true });

            expect(visit.mock.calls[0][0]).toContain('general');
        });

        it('wraps around the end of the list', () => {
            seed('design');

            press('ArrowDown', { altKey: true });

            expect(visit.mock.calls[0][0]).toContain('general');
        });

        it('does nothing until a workspace is loaded', () => {
            page.props = { channels: [{ slug: 'general' }] };

            press('ArrowDown', { altKey: true });

            expect(visit).not.toHaveBeenCalled();
        });
    });

    it('stops listening once the shell it was bound to is gone', () => {
        app?.unmount();
        app = null;

        press('k', { metaKey: true });

        expect(useDialog('switcher').isOpen.value).toBe(false);
    });
});
