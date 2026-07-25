// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h } from 'vue';

/** Mutable stand-in for the shared `demoMode` Inertia prop. */
const props = vi.hoisted(() => ({ demoMode: false as boolean }));

/** Mutable stand-in for the in-flight state Inertia's <Form> yields. */
const form = vi.hoisted(() => ({ processing: false as boolean }));

// Stub Inertia's <Form> down to a plain <form> that exposes the action/method it
// was handed, so the test asserts where the CTA posts rather than exercising
// Inertia's request pipeline.
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props }),
    Form: defineComponent({
        name: 'InertiaFormStub',
        props: {
            action: { type: String, default: '' },
            method: { type: String, default: 'get' },
        },
        setup:
            (formProps, { slots }) =>
            () =>
                h(
                    'form',
                    { action: formProps.action, method: formProps.method },
                    slots.default?.({ processing: form.processing }),
                ),
    }),
}));

import DemoEnterButton from './DemoEnterButton.vue';

let app: App | null = null;

function mount() {
    const host = document.createElement('div');
    document.body.appendChild(host);

    app = createApp({
        render: () => h(DemoEnterButton, { class: 'w-full' }),
    });
    app.config.globalProperties.$t = (key: string) => key;
    app.mount(host);

    return host;
}

afterEach(() => {
    app?.unmount();
    app = null;
    props.demoMode = false;
    form.processing = false;
    document.body.innerHTML = '';
});

describe('DemoEnterButton', () => {
    it('renders nothing off the demo, so a real deployment has no dead CTA', () => {
        props.demoMode = false;

        const host = mount();

        expect(
            host.querySelector('[data-test="demo-enter-button"]'),
        ).toBeNull();
        expect(host.querySelector('form')).toBeNull();
    });

    it('posts to the demo entry route in the demo', () => {
        props.demoMode = true;

        const host = mount();

        const form = host.querySelector('form');
        const button = host.querySelector<HTMLButtonElement>(
            '[data-test="demo-enter-button"]',
        );

        expect(form?.getAttribute('action')).toBe('/demo/login');
        expect(form?.getAttribute('method')).toBe('post');
        expect(button?.type).toBe('submit');
        expect(button?.textContent).toContain('Enter the demo');
    });

    it('blocks a second press while the entry request is in flight', () => {
        props.demoMode = true;
        form.processing = true;

        const host = mount();

        const button = host.querySelector<HTMLButtonElement>(
            '[data-test="demo-enter-button"]',
        );

        expect(button?.disabled).toBe(true);
        expect(button?.getAttribute('aria-busy')).toBe('true');
    });

    it('forwards fallthrough attributes onto the button, not the form', () => {
        props.demoMode = true;

        const host = mount();

        expect(
            host
                .querySelector('[data-test="demo-enter-button"]')
                ?.classList.contains('w-full'),
        ).toBe(true);
        expect(host.querySelector('form')?.classList.contains('w-full')).toBe(
            false,
        );
    });
});
