// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h, nextTick, ref } from 'vue';

/**
 * Exercises the delete-channel dialog's own logic: the typed-name gate on the
 * confirm button, the on-open fetch of the destruction summary, its degradation
 * when that fetch fails, and the reset that stops one channel's count from being
 * shown for the next. `ConfirmDialog` is stubbed down to the two things this
 * component drives through it — the confirm button's disabled state and the
 * slots — so the assertions are about this component, not reka's dialog.
 */
vi.mock('@/components/ConfirmDialog.vue', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        default: defineComponent({
            name: 'ConfirmDialogStub',
            props: {
                title: { type: String, default: '' },
                confirmLabel: { type: String, default: '' },
                confirmDisabled: { type: Boolean, default: false },
                confirmDataTest: { type: String, default: '' },
                submit: { type: Object, default: () => ({}) },
            },
            setup(props, { slots }) {
                return () =>
                    h('div', { 'data-stub': 'ConfirmDialog' }, [
                        h('h2', props.title),
                        h('p', slots.description?.()),
                        h('div', slots.body?.({ errors: {} })),
                        h('button', {
                            'data-test': props.confirmDataTest,
                            disabled: props.confirmDisabled,
                        }),
                    ]);
            },
        }),
    };
});

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { channelRestoreWindowDays: 30 } }),
}));

vi.mock('@/routes/channels', () => ({
    destroy: { form: () => ({ action: '/t/acme/c/roadmap', method: 'post' }) },
    deletionSummary: () => ({
        url: '/t/acme/c/roadmap/deletion-summary',
    }),
}));

import DeleteChannelDialog from './DeleteChannelDialog.vue';

let active: Array<{ app: App; container: HTMLElement }> = [];

function mount(): {
    container: HTMLElement;
    setOpen: (value: boolean) => void;
} {
    const container = document.createElement('div');
    document.body.appendChild(container);

    // The dialog is a controlled component, so the harness owns `open` and hands
    // the test a setter — that is what drives the on-open fetch and on-close reset.
    const open = ref(false);

    const app = createApp(
        defineComponent({
            setup() {
                return () =>
                    h(DeleteChannelDialog, {
                        open: open.value,
                        'onUpdate:open': (value: boolean) => {
                            open.value = value;
                        },
                        teamSlug: 'acme',
                        channelName: 'Roadmap',
                        channelSlug: 'roadmap',
                    });
            },
        }),
    );

    app.config.globalProperties.$t = (
        key: string,
        replacements: Record<string, string | number> = {},
    ) =>
        Object.entries(replacements).reduce(
            (line, [token, value]) =>
                line.replaceAll(`:${token}`, String(value)),
            key,
        );
    app.mount(container);
    active.push({ app, container });

    return {
        container,
        setOpen: (value: boolean) => {
            open.value = value;
        },
    };
}

const confirm = (container: HTMLElement) =>
    container.querySelector<HTMLButtonElement>(
        '[data-test="delete-channel-confirm"]',
    );

const nameInput = (container: HTMLElement) =>
    container.querySelector<HTMLInputElement>(
        '[data-test="delete-channel-name"]',
    );

async function type(container: HTMLElement, value: string): Promise<void> {
    const input = nameInput(container)!;
    input.value = value;
    input.dispatchEvent(new Event('input'));
    await nextTick();
}

beforeEach(() => {
    vi.stubGlobal(
        'fetch',
        vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () =>
                    Promise.resolve({
                        messageCount: 3412,
                        fileCount: 88,
                        memberCount: 12,
                    }),
            }),
        ),
    );
});

afterEach(() => {
    for (const { app, container } of active) {
        app.unmount();
        container.remove();
    }

    active = [];
    vi.unstubAllGlobals();
});

describe('DeleteChannelDialog', () => {
    it('keeps the confirm button disabled until the channel name is typed exactly', async () => {
        const { container } = mount();

        expect(confirm(container)?.disabled).toBe(true);

        await type(container, 'roadmap');
        expect(confirm(container)?.disabled).toBe(true);

        await type(container, 'Roadmap');
        expect(confirm(container)?.disabled).toBe(false);
    });

    it('fetches and shows what would be destroyed once it is opened', async () => {
        const { container, setOpen } = mount();

        expect(fetch).not.toHaveBeenCalled();

        setOpen(true);
        await nextTick();
        await Promise.resolve();
        await nextTick();

        expect(fetch).toHaveBeenCalledWith(
            '/t/acme/c/roadmap/deletion-summary',
            { headers: { Accept: 'application/json' } },
        );
        expect(
            container.querySelector('[data-test="delete-channel-summary"]')
                ?.textContent,
        ).toContain('3,412 messages');
    });

    it('names the categories instead when the count cannot be loaded', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.reject(new Error('offline'))),
        );

        const { container, setOpen } = mount();

        setOpen(true);
        await nextTick();
        await Promise.resolve();
        await nextTick();

        expect(
            container.querySelector(
                '[data-test="delete-channel-summary-fallback"]',
            ),
        ).not.toBeNull();
    });

    it('clears the typed name and the loaded count when it closes', async () => {
        const { container, setOpen } = mount();

        setOpen(true);
        await nextTick();
        await Promise.resolve();
        await nextTick();

        await type(container, 'Roadmap');
        expect(confirm(container)?.disabled).toBe(false);

        setOpen(false);
        await nextTick();

        expect(nameInput(container)?.value).toBe('');
        expect(confirm(container)?.disabled).toBe(true);
        expect(
            container.querySelector('[data-test="delete-channel-summary"]'),
        ).toBeNull();
    });
});
