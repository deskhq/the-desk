// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h, nextTick, ref } from 'vue';
import { translate } from '@/lib/i18n';

/**
 * Covers the one thing the modal decides for itself: which visibilities it may
 * offer. The workspace's channel-creation policy is held per visibility, so a
 * member the policy shuts out of public channels must not be shown the option —
 * and a member shut out of both is not shown the affordance at all, matching the
 * 403 the create endpoint would answer with.
 *
 * It is a shell singleton rather than a wrapper around its own triggers (#1223),
 * so the way in is `v-model:open` and there is no slot to click.
 */
const page = vi.hoisted(
    (): { props: { creatableChannelVisibilities: string[] } } => ({
        props: { creatableChannelVisibilities: ['public', 'private'] },
    }),
);

vi.mock('@inertiajs/vue3', async () => {
    // Reactive, because the real shared props are: the singleton outlives a
    // workspace switch, and a double that could not change under it would let a
    // stale reading pass.
    const { reactive } = await import('vue');

    page.props = reactive(page.props);

    return {
        usePage: () => page,
        Form: defineComponent({
            props: { action: { type: String, default: '' } },
            setup:
                (props, { slots }) =>
                () =>
                    h(
                        'form',
                        { action: props.action },
                        slots.default?.({ errors: {}, processing: false }),
                    ),
        }),
    };
});

vi.mock('@/actions/App/Http/Controllers/Channels/ChannelController', () => ({
    store: { form: (slug: string) => ({ action: `/t/${slug}/channels` }) },
}));

vi.mock('@/components/ui/select', () => ({
    Select: defineComponent({
        props: {
            modelValue: { type: String, default: '' },
            name: { type: String, default: '' },
        },
        emits: ['update:modelValue'],
        setup:
            (props, { slots, emit }) =>
            () =>
                h(
                    'select',
                    {
                        name: props.name,
                        value: props.modelValue,
                        onChange: (event: Event) =>
                            emit(
                                'update:modelValue',
                                (event.target as HTMLSelectElement).value,
                            ),
                    },
                    slots.default?.(),
                ),
    }),
    SelectContent: defineComponent({
        setup:
            (_props, { slots }) =>
            () =>
                slots.default?.(),
    }),
    SelectItem: defineComponent({
        props: { value: { type: String, default: '' } },
        setup:
            (props, { slots }) =>
            () =>
                h('option', { value: props.value }, slots.default?.()),
    }),
    SelectTrigger: defineComponent({ setup: () => () => h('span') }),
    SelectValue: defineComponent({ setup: () => () => h('span') }),
}));

import CreateChannelModal from './CreateChannelModal.vue';

let app: App | null = null;

/** The host's `v-model:open`, which is the only way in now. */
const isOpen = ref(false);

function mount(visibilities: string[]): void {
    page.props.creatableChannelVisibilities = visibilities;
    isOpen.value = false;

    const host = document.createElement('div');
    document.body.append(host);

    app = createApp({
        render: () =>
            h(CreateChannelModal, {
                teamSlug: 'acme',
                open: isOpen.value,
                'onUpdate:open': (value: boolean) => {
                    isOpen.value = value;
                },
            }),
    });
    app.config.globalProperties.$t = translate;
    app.mount(host);
}

/** Opens the dialog the way the shell does, and lets the form render. */
async function open(): Promise<void> {
    isOpen.value = true;
    await nextTick();
    await nextTick();
}

/** The dialog renders into a portal, so the whole document is the haystack. */
function visibilityOptions(): string[] {
    return [
        ...document.querySelectorAll<HTMLOptionElement>(
            '[data-test="create-channel-visibility"] option',
        ),
    ].map((option) => option.value);
}

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

describe('the create-channel modal', () => {
    it('offers both visibilities while the workspace leaves both open', async () => {
        mount(['public', 'private']);
        await open();

        expect(visibilityOptions()).toEqual(['public', 'private']);
    });

    it('offers only the visibility the policy leaves open', async () => {
        mount(['private']);
        await open();

        expect(visibilityOptions()).toEqual(['private']);
    });

    it('starts on the only visibility left rather than a refused default', async () => {
        mount(['private']);
        await open();

        expect(
            document.querySelector<HTMLSelectElement>(
                '[data-test="create-channel-visibility"]',
            )?.value,
        ).toBe('private');
    });

    it('forgets a half-finished choice when the modal is closed and reopened', async () => {
        mount(['public', 'private']);
        await open();

        const control = document.querySelector<HTMLSelectElement>(
            '[data-test="create-channel-visibility"]',
        ) as HTMLSelectElement;
        control.value = 'private';
        control.dispatchEvent(new Event('change'));
        await nextTick();

        isOpen.value = false;
        await nextTick();
        await open();

        expect(
            document.querySelector<HTMLSelectElement>(
                '[data-test="create-channel-visibility"]',
            )?.value,
        ).toBe('public');
    });

    it('re-reads the policy on the way in, not on the way out', async () => {
        // The singleton outlives a workspace switch, where the last default it
        // reset to may no longer be offered at all. Reading on close would leave
        // the picker holding a value with no option under it.
        mount(['public', 'private']);
        await open();

        isOpen.value = false;
        await nextTick();

        page.props.creatableChannelVisibilities = ['private'];
        await open();

        expect(
            document.querySelector<HTMLSelectElement>(
                '[data-test="create-channel-visibility"]',
            )?.value,
        ).toBe('private');
    });

    it('writes a dismissal back to the host, so its opener sees the dialog go', async () => {
        // `v-model:open` is now the whole contract: bound to a copy, the "+"
        // that opened it would still believe it up and do nothing on a second
        // press.
        mount(['public', 'private']);
        await open();

        document
            .querySelector<HTMLElement>('[data-test="dialog-close-button"]')
            ?.click();
        await nextTick();

        expect(isOpen.value).toBe(false);
    });

    it('mounts nothing at all when the policy leaves neither open', async () => {
        // The affordances that open it are gated on the same reading, so this is
        // the backstop rather than the gate: asked to open anyway, it declines.
        mount([]);
        await open();

        expect(
            document.querySelector('[data-slot="dialog-content"]'),
        ).toBeNull();
        expect(
            document.querySelector('[data-test="create-channel-submit"]'),
        ).toBeNull();
        expect(visibilityOptions()).toEqual([]);
    });
});
