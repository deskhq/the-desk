// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { App, Component, VNode } from 'vue';
import { createApp, defineComponent, h, nextTick } from 'vue';

/**
 * Renders `<ConfirmDialog>` under jsdom with the heavy primitives stubbed so the
 * module's own logic — the visit engine, close-on-success, focus-on-error, and
 * the submit/type split between the two submission engines — is what the tests
 * exercise. Inertia's `router.visit` is a spy whose options callbacks are
 * invoked by hand to drive the pending/success transitions deterministically.
 * The real `Button` is kept so its `loading`/`variant` wiring is proven end to end.
 */
const visit = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h, onMounted, ref } = await import('vue');

    const Form = defineComponent({
        name: 'FormStub',
        props: {
            action: { type: String, default: '' },
            method: { type: String, default: '' },
            resetOnSuccess: { type: Boolean, default: false },
            options: { type: Object, default: () => ({}) },
        },
        emits: ['error', 'success'],
        setup(props, { slots, emit }) {
            const el = ref<HTMLElement | null>(null);

            // Let a test fire an Inertia error/success without real network I/O.
            onMounted(() => {
                el.value?.addEventListener('trigger-error', () =>
                    emit('error'),
                );
                el.value?.addEventListener('trigger-success', () =>
                    emit('success'),
                );
            });

            return () =>
                h(
                    'form',
                    {
                        ref: el,
                        'data-stub': 'form',
                        'data-action': props.action,
                        'data-reset-on-success': String(props.resetOnSuccess),
                    },
                    slots.default?.({ errors: {}, processing: false }),
                );
        },
    });

    return { router: { visit }, Form };
});

vi.mock('@/components/ui/dialog', async () => {
    const { defineComponent, h, onMounted, ref } = await import('vue');

    const passthrough = (name: string) =>
        defineComponent({
            name,
            setup(_, { slots }) {
                return () => h('div', { 'data-stub': name }, slots.default?.());
            },
        });

    const Dialog = defineComponent({
        name: 'DialogStub',
        props: { open: { type: Boolean, default: undefined } },
        emits: ['update:open'],
        setup(props, { slots, emit }) {
            const el = ref<HTMLElement | null>(null);

            onMounted(() => {
                el.value?.addEventListener('emit-open', () =>
                    emit('update:open', true),
                );
                el.value?.addEventListener('emit-close', () =>
                    emit('update:open', false),
                );
            });

            return () =>
                h(
                    'div',
                    {
                        ref: el,
                        'data-stub': 'Dialog',
                        'data-open': String(props.open),
                    },
                    slots.default?.(),
                );
        },
    });

    return {
        Dialog,
        DialogClose: passthrough('DialogClose'),
        DialogContent: passthrough('DialogContent'),
        DialogDescription: passthrough('DialogDescription'),
        DialogFooter: passthrough('DialogFooter'),
        DialogHeader: passthrough('DialogHeader'),
        DialogTitle: passthrough('DialogTitle'),
        DialogTrigger: passthrough('DialogTrigger'),
    };
});

import ConfirmDialog from './ConfirmDialog.vue';

type Slots = Record<
    string,
    (scope: { errors: Record<string, string> }) => VNode | VNode[] | string
>;

let active: Array<{ app: App; container: HTMLElement }> = [];

function mount(props: Record<string, unknown>, slots: Slots = {}) {
    const openHandler = vi.fn();
    const container = document.createElement('div');
    document.body.appendChild(container);

    const vnodeProps: Record<string, unknown> = {
        ...props,
        'onUpdate:open': openHandler,
    };
    const app = createApp(
        defineComponent({
            setup() {
                return () => h(ConfirmDialog as Component, vnodeProps, slots);
            },
        }),
    );
    app.config.globalProperties.$t = (key: string) => key;
    app.mount(container);
    active.push({ app, container });

    const confirm = () =>
        container.querySelector<HTMLButtonElement>(
            `[data-test="${props.confirmDataTest}"]`,
        );

    return { container, openHandler, confirm };
}

afterEach(() => {
    for (const { app, container } of active) {
        app.unmount();
        container.remove();
    }

    active = [];
    visit.mockClear();
});

const leaveProps = {
    title: 'Leave team',
    confirmLabel: 'Leave team',
    confirmDataTest: 'leave-team-confirm',
    submit: { visit: '/teams/acme/leave' },
};

describe('ConfirmDialog', () => {
    it('renders a visit-mode confirmation with its title, labels and data-test', () => {
        const { container, confirm } = mount(leaveProps, {
            description: () => 'Are you sure you want to leave Acme?',
        });

        expect(container.textContent).toContain('Leave team');
        expect(container.textContent).toContain(
            'Are you sure you want to leave',
        );
        // The visit engine confirm is a plain button, never a form submit.
        expect(confirm()?.getAttribute('type')).toBe('button');
        expect(container.textContent).toContain('Cancel');
    });

    it('fires router.visit on confirm and closes once the request succeeds', async () => {
        const { confirm, openHandler } = mount(leaveProps);

        confirm()!.click();

        expect(visit).toHaveBeenCalledOnce();
        const [url, options] = visit.mock.calls[0] as [
            string,
            {
                onStart: () => void;
                onFinish: () => void;
                onSuccess: () => void;
            },
        ];
        expect(url).toBe('/teams/acme/leave');

        // Pending toggles the loading/disabled state on the shared Button...
        options.onStart();
        await nextTick();
        expect(confirm()?.getAttribute('aria-busy')).toBe('true');
        expect(confirm()?.hasAttribute('disabled')).toBe(true);

        options.onFinish();
        await nextTick();
        expect(confirm()?.getAttribute('aria-busy')).toBeNull();

        // ...and success closes the dialog.
        options.onSuccess();
        expect(openHandler).toHaveBeenCalledWith(false);
    });

    it('renders a form-mode confirmation as a submit inside an Inertia form', () => {
        const { container, confirm } = mount(
            {
                title: 'Transfer team ownership',
                confirmLabel: 'Transfer ownership',
                confirmVariant: 'default',
                confirmDataTest: 'transfer-ownership-confirm',
                resetOnSuccess: true,
                submit: {
                    form: { action: '/teams/acme/transfer', method: 'post' },
                },
            },
            {
                body: () =>
                    h('input', {
                        name: 'password',
                        'data-test': 'transfer-password',
                    }),
            },
        );

        const form = container.querySelector('[data-stub="form"]');
        expect(form?.getAttribute('data-action')).toBe('/teams/acme/transfer');
        expect(form?.getAttribute('data-reset-on-success')).toBe('true');
        expect(confirm()?.getAttribute('type')).toBe('submit');
        expect(
            container.querySelector('[data-test="transfer-password"]'),
        ).not.toBeNull();
    });

    it('refocuses the first field after a rejected submit', async () => {
        const { container } = mount(
            {
                title: 'Delete account',
                confirmLabel: 'Delete account',
                confirmDataTest: 'confirm-delete-user-button',
                submit: { form: { action: '/profile', method: 'post' } },
            },
            { body: () => h('input', { name: 'password' }) },
        );

        const input = container.querySelector('input')!;
        expect(document.activeElement).not.toBe(input);

        container
            .querySelector('[data-stub="form"]')!
            .dispatchEvent(new Event('trigger-error'));
        await nextTick();

        expect(document.activeElement).toBe(input);
    });

    it('closes the dialog after a successful form submit', () => {
        const { container, openHandler } = mount(
            {
                title: 'Delete account',
                confirmLabel: 'Delete account',
                confirmDataTest: 'confirm-delete-user-button',
                submit: { form: { action: '/profile', method: 'post' } },
            },
            { body: () => h('input', { name: 'password' }) },
        );

        container
            .querySelector('[data-stub="form"]')!
            .dispatchEvent(new Event('trigger-success'));

        expect(openHandler).toHaveBeenCalledWith(false);
    });

    it('blocks confirm through confirmDisabled without a spinner', () => {
        const { confirm } = mount({ ...leaveProps, confirmDisabled: true });

        expect(confirm()?.hasAttribute('disabled')).toBe(true);
        expect(confirm()?.getAttribute('aria-busy')).toBeNull();
    });

    it('defaults to the destructive variant and honors an override', () => {
        const { confirm: destructive } = mount(leaveProps);
        expect(destructive()?.className).toContain('bg-destructive');

        const { confirm: neutral } = mount({
            ...leaveProps,
            confirmVariant: 'default',
        });
        expect(neutral()?.className).toContain('bg-primary');
    });

    it('renders the trigger slot through a DialogTrigger for the self-contained family', () => {
        const { container } = mount(
            {
                title: 'Log out other devices?',
                confirmLabel: 'Log out other devices',
                confirmDataTest: 'confirm-revoke-others',
                submit: { form: { action: '/sessions', method: 'post' } },
            },
            {
                trigger: () =>
                    h(
                        'button',
                        { 'data-test': 'revoke-others-button' },
                        'Open',
                    ),
                body: () => h('input', { name: 'password' }),
            },
        );

        // reka's DialogTrigger owns open/close for this family; the caller's
        // trigger button is projected through it.
        const trigger = container.querySelector('[data-stub="DialogTrigger"]');
        expect(
            trigger?.querySelector('[data-test="revoke-others-button"]'),
        ).not.toBeNull();
    });

    it('accepts a custom cancel label', () => {
        const { container } = mount({
            ...leaveProps,
            cancelLabel: 'Keep invitation',
        });

        expect(container.textContent).toContain('Keep invitation');
    });
});
