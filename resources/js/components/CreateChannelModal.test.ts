// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h, nextTick } from 'vue';
import { translate } from '@/lib/i18n';

/**
 * Covers the one thing the modal decides for itself: which visibilities it may
 * offer. The workspace's channel-creation policy is held per visibility, so a
 * member the policy shuts out of public channels must not be shown the option —
 * and a member shut out of both is not shown the affordance at all, matching the
 * 403 the create endpoint would answer with.
 */
const pageProps = vi.hoisted(
    (): { creatableChannelVisibilities: string[] } => ({
        creatableChannelVisibilities: ['public', 'private'],
    }),
);

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: pageProps }),
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
}));

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

function mount(visibilities: string[]): HTMLElement {
    pageProps.creatableChannelVisibilities = visibilities;

    const host = document.createElement('div');
    document.body.append(host);

    app = createApp({
        render: () =>
            h(CreateChannelModal, { teamSlug: 'acme' }, () =>
                h('button', { 'data-test': 'trigger' }, 'New channel'),
            ),
    });
    app.config.globalProperties.$t = translate;
    app.mount(host);

    return host;
}

/** Opens the dialog through its trigger, which is how the form is ever seen. */
async function open(host: HTMLElement): Promise<void> {
    host.querySelector<HTMLElement>('[data-test="trigger"]')?.click();
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
        await open(mount(['public', 'private']));

        expect(visibilityOptions()).toEqual(['public', 'private']);
    });

    it('offers only the visibility the policy leaves open', async () => {
        await open(mount(['private']));

        expect(visibilityOptions()).toEqual(['private']);
    });

    it('starts on the only visibility left rather than a refused default', async () => {
        await open(mount(['private']));

        expect(
            document.querySelector<HTMLSelectElement>(
                '[data-test="create-channel-visibility"]',
            )?.value,
        ).toBe('private');
    });

    it('forgets a half-finished choice when the modal is closed and reopened', async () => {
        const host = mount(['public', 'private']);
        await open(host);

        const control = document.querySelector<HTMLSelectElement>(
            '[data-test="create-channel-visibility"]',
        ) as HTMLSelectElement;
        control.value = 'private';
        control.dispatchEvent(new Event('change'));
        await nextTick();

        // Close through the same path the dialog itself uses, then reopen.
        host.querySelector<HTMLElement>('[data-test="trigger"]')?.click();
        await nextTick();
        await open(host);

        expect(
            document.querySelector<HTMLSelectElement>(
                '[data-test="create-channel-visibility"]',
            )?.value,
        ).toBe('public');
    });

    it('withdraws the affordance entirely when neither is open', () => {
        const host = mount([]);

        expect(host.querySelector('[data-test="trigger"]')).toBeNull();
        expect(visibilityOptions()).toEqual([]);
    });
});
