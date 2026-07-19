// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { App, Component } from 'vue';
import { createApp, defineComponent, h, nextTick } from 'vue';
import PollComposerPanel from './PollComposerPanel.vue';

/**
 * Mounts the panel with the real `Input`/`Switch`/`Button` primitives on
 * purpose: issue #577 (dead controls + a `mounted` focus crash) was invisible
 * to tests that stubbed them, because the crash came from treating the shadcn
 * `Input` component instance as a bare `HTMLInputElement`.
 */

const routerPost = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: { post: (...args: unknown[]) => routerPost(...args) },
}));

vi.mock('@/actions/App/Http/Controllers/Channels/PollController', () => ({
    store: () => ({ url: '/t/acme/c/general/polls' }),
}));

let active: Array<{ app: App; container: HTMLElement }> = [];

function mountPanel() {
    const closed: number[] = [];
    const errors: unknown[] = [];
    const container = document.createElement('div');
    document.body.appendChild(container);

    const app = createApp(
        defineComponent({
            setup: () => () =>
                h(PollComposerPanel as Component, {
                    teamSlug: 'acme',
                    channelSlug: 'general',
                    onClose: () => closed.push(1),
                }),
        }),
    );
    app.config.globalProperties.$t = (key: string) => key;
    app.config.errorHandler = (error) => errors.push(error);
    app.mount(container);
    active.push({ app, container });

    return { container, closed, errors };
}

function optionInputs(container: HTMLElement): HTMLInputElement[] {
    return Array.from(
        container.querySelectorAll<HTMLInputElement>(
            '[data-test="poll-option-input"]',
        ),
    );
}

function click(element: Element | null): Promise<void> {
    element!.dispatchEvent(
        new MouseEvent('click', { bubbles: true, cancelable: true }),
    );

    return nextTick();
}

async function fill(input: HTMLInputElement, value: string): Promise<void> {
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));

    await nextTick();
}

afterEach(() => {
    active.forEach(({ app, container }) => {
        app.unmount();
        container.remove();
    });
    active = [];
    vi.clearAllMocks();
});

describe('PollComposerPanel', () => {
    it('mounts without errors and focuses the question field', async () => {
        const { container, errors } = mountPanel();

        const question = container.querySelector<HTMLInputElement>(
            '[data-test="poll-question-input"]',
        );

        // The focus is deferred a tick (mirroring GifPickerPanel) so it lands
        // after the composer's own post-open focus work.
        await nextTick();

        expect(errors).toEqual([]);
        expect(question).not.toBeNull();
        expect(document.activeElement).toBe(question);
    });

    it('appends option rows up to the max of 10', async () => {
        const { container } = mountPanel();

        expect(optionInputs(container)).toHaveLength(2);

        await click(container.querySelector('[data-test="poll-add-option"]'));
        expect(optionInputs(container)).toHaveLength(3);

        for (let rows = 3; rows < 10; rows++) {
            await click(
                container.querySelector('[data-test="poll-add-option"]'),
            );
        }

        expect(optionInputs(container)).toHaveLength(10);

        // At the max the add button is gone, so the count cannot grow further.
        expect(
            container.querySelector('[data-test="poll-add-option"]'),
        ).toBeNull();
    });

    it('removes option rows down to the min of 2', async () => {
        const { container } = mountPanel();

        // At the minimum no remove buttons render at all.
        expect(
            container.querySelectorAll('[data-test="poll-option-remove"]'),
        ).toHaveLength(0);

        await click(container.querySelector('[data-test="poll-add-option"]'));
        expect(optionInputs(container)).toHaveLength(3);

        await click(
            container.querySelector('[data-test="poll-option-remove"]'),
        );
        expect(optionInputs(container)).toHaveLength(2);
        expect(
            container.querySelectorAll('[data-test="poll-option-remove"]'),
        ).toHaveLength(0);
    });

    it('removing a row keeps the other rows in order', async () => {
        const { container } = mountPanel();

        await click(container.querySelector('[data-test="poll-add-option"]'));

        const inputs = optionInputs(container);
        await fill(inputs[0], 'first');
        await fill(inputs[1], 'second');
        await fill(inputs[2], 'third');

        const removeButtons = container.querySelectorAll(
            '[data-test="poll-option-remove"]',
        );
        await click(removeButtons[1]);

        expect(optionInputs(container).map((input) => input.value)).toEqual([
            'first',
            'third',
        ]);
    });

    it('closes via the × button', async () => {
        const { container, closed } = mountPanel();

        await click(
            container.querySelector('[data-test="poll-builder-close"]'),
        );

        expect(closed).toHaveLength(1);
    });

    it('closes via Escape', async () => {
        const { container, closed } = mountPanel();

        container.querySelector('[data-test="poll-builder"]')!.dispatchEvent(
            new KeyboardEvent('keydown', {
                key: 'Escape',
                bubbles: true,
                cancelable: true,
            }),
        );
        await nextTick();

        expect(closed).toHaveLength(1);
    });

    it('closes via the cancel button', async () => {
        const { container, closed } = mountPanel();

        await click(container.querySelector('[data-test="poll-cancel"]'));

        expect(closed).toHaveLength(1);
    });

    it('toggles both switches and submits their values with the poll', async () => {
        const { container } = mountPanel();

        const question = container.querySelector<HTMLInputElement>(
            '[data-test="poll-question-input"]',
        )!;
        await fill(question, 'Lunch spot?');

        const inputs = optionInputs(container);
        await fill(inputs[0], 'Tacos');
        await fill(inputs[1], 'Ramen');

        const allowMultiple = container.querySelector(
            '[data-test="poll-allow-multiple"]',
        )!;
        const anonymous = container.querySelector(
            '[data-test="poll-anonymous"]',
        )!;

        expect(allowMultiple.getAttribute('data-state')).toBe('unchecked');
        expect(anonymous.getAttribute('data-state')).toBe('unchecked');

        await click(allowMultiple);
        await click(anonymous);

        expect(allowMultiple.getAttribute('data-state')).toBe('checked');
        expect(anonymous.getAttribute('data-state')).toBe('checked');

        await click(container.querySelector('[data-test="poll-submit"]'));

        expect(routerPost).toHaveBeenCalledTimes(1);
        expect(routerPost.mock.calls[0][0]).toBe('/t/acme/c/general/polls');
        expect(routerPost.mock.calls[0][1]).toMatchObject({
            question: 'Lunch spot?',
            options: ['Tacos', 'Ramen'],
            allow_multiple: true,
            is_anonymous: true,
        });
    });

    it('a toggled switch can be toggled back off', async () => {
        const { container } = mountPanel();

        const allowMultiple = container.querySelector(
            '[data-test="poll-allow-multiple"]',
        )!;

        await click(allowMultiple);
        expect(allowMultiple.getAttribute('data-state')).toBe('checked');

        await click(allowMultiple);
        expect(allowMultiple.getAttribute('data-state')).toBe('unchecked');
    });
});
