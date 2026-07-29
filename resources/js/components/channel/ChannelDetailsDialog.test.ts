// @vitest-environment jsdom
import { afterEach, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h, nextTick } from 'vue';
import type { Channel } from '@/types';

/**
 * The channel-details modal: what it says about the channel when it opens, and
 * which of its fields the viewer is allowed to change. The dialog primitives and
 * the Inertia `<Form>` are stubbed to passthroughs, so what is exercised is this
 * component's own content and gating rather than reka-ui or Inertia.
 */
vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent: define, h: create } = await import('vue');

    return {
        Form: define({
            name: 'FormStub',
            setup:
                (_, { slots }) =>
                () =>
                    create(
                        'form',
                        slots.default?.({ errors: {}, processing: false }),
                    ),
        }),
    };
});

vi.mock('@/actions/App/Http/Controllers/Channels/ChannelController', () => ({
    update: { form: () => ({ action: '/patch', method: 'patch' }) },
}));

vi.mock('@/components/ui/dialog', async () => {
    const { defineComponent: define, h: create } = await import('vue');

    const passthrough = (name: string, tag = 'div') =>
        define({
            name,
            setup:
                (_, { slots }) =>
                () =>
                    create(tag, { 'data-stub': name }, slots.default?.()),
        });

    return {
        Dialog: define({
            name: 'DialogStub',
            props: { open: { type: Boolean, default: false } },
            setup:
                (props, { slots }) =>
                () =>
                    props.open ? create('div', slots.default?.()) : null,
        }),
        DialogClose: passthrough('DialogClose'),
        DialogContent: passthrough('DialogContent'),
        DialogDescription: passthrough('DialogDescription', 'p'),
        DialogFooter: passthrough('DialogFooter'),
        DialogHeader: passthrough('DialogHeader'),
        DialogTitle: passthrough('DialogTitle', 'h2'),
    };
});

let app: App | null = null;

function translate(
    key: string,
    replacements?: Record<string, unknown>,
): string {
    if (replacements === undefined) {
        return key;
    }

    return Object.entries(replacements).reduce(
        (line, [token, value]) => line.replaceAll(`:${token}`, String(value)),
        key,
    );
}

function channel(overrides: Partial<Channel> = {}): Channel {
    return {
        id: 'c1',
        name: 'marketing',
        slug: 'marketing',
        visibility: 'public',
        topic: null,
        description: null,
        isGeneral: false,
        isArchived: false,
        muted: false,
        notificationLevel: 'all',
        unreadCount: 0,
        mentionCount: 0,
        hasDraft: false,
        draft: null,
        starred: false,
        sectionId: null,
        position: 0,
        isDirect: false,
        isGroupDirect: false,
        dmUserId: null,
        dmParticipants: null,
        lastActivityAt: null,
        ...overrides,
    };
}

async function mountDialog(options: {
    channel?: Channel;
    canEdit?: boolean;
    canRename?: boolean;
}): Promise<HTMLElement> {
    const ChannelDetailsDialog = (await import('./ChannelDetailsDialog.vue'))
        .default;

    const host = document.createElement('div');
    document.body.appendChild(host);

    app = createApp(
        defineComponent({
            render: () =>
                h(ChannelDetailsDialog, {
                    open: true,
                    'onUpdate:open': () => undefined,
                    channel: options.channel ?? channel(),
                    teamSlug: 'acme',
                    canEdit: options.canEdit ?? true,
                    canRename: options.canRename ?? false,
                }),
        }),
    );
    app.config.globalProperties.$t = translate;
    app.mount(host);

    await nextTick();

    return host;
}

function click(host: HTMLElement, selector: string): Promise<void> {
    host.querySelector<HTMLElement>(`[data-test="${selector}"]`)?.click();

    return nextTick();
}

afterEach(async () => {
    app?.unmount();
    app = null;
    await nextTick();
    document.body.innerHTML = '';
});

it('renders the description with links and basic formatting', async () => {
    const host = await mountDialog({
        channel: channel({
            description: 'The **launch** channel — see https://example.com',
        }),
    });

    const rendered = host.querySelector(
        '[data-test="channel-details-description-value"]',
    );

    expect(rendered?.querySelector('strong')?.textContent).toBe('launch');
    expect(rendered?.querySelector('a')?.getAttribute('href')).toBe(
        'https://example.com',
    );
});

it('leaves the topic as plain text', async () => {
    const host = await mountDialog({
        channel: channel({ topic: 'Campaigns **and** launches' }),
    });

    const topic = host.querySelector(
        '[data-test="channel-details-topic-value"]',
    );

    expect(topic?.textContent?.trim()).toBe('Campaigns **and** launches');
    expect(topic?.querySelector('strong')).toBeNull();
});

it('says so when there is nothing to show yet', async () => {
    const host = await mountDialog({});

    expect(
        host.querySelector('[data-test="channel-details-topic-value"]')
            ?.textContent,
    ).toContain('No topic set');
    expect(
        host.querySelector('[data-test="channel-details-description-value"]')
            ?.textContent,
    ).toContain('No description yet');
});

it('offers no way into the form when the viewer may not edit', async () => {
    const host = await mountDialog({ canEdit: false });

    expect(host.querySelector('[data-test="channel-details-edit"]')).toBeNull();
});

it('opens the form on the channel as it stands, without the name field', async () => {
    const host = await mountDialog({
        channel: channel({ topic: 'Campaigns', description: 'The purpose.' }),
    });

    await click(host, 'channel-details-edit');

    expect(
        host.querySelector<HTMLInputElement>(
            '[data-test="channel-details-topic"]',
        )?.value,
    ).toBe('Campaigns');
    expect(
        host.querySelector<HTMLTextAreaElement>(
            '[data-test="channel-details-description"]',
        )?.value,
    ).toBe('The purpose.');
    expect(host.querySelector('[data-test="channel-details-name"]')).toBeNull();
});

it('offers the name field only to a viewer who may rename', async () => {
    const host = await mountDialog({ canRename: true });

    await click(host, 'channel-details-edit');

    expect(
        host.querySelector<HTMLInputElement>(
            '[data-test="channel-details-name"]',
        )?.value,
    ).toBe('marketing');
});

it('returns to the details on cancel', async () => {
    const host = await mountDialog({});

    await click(host, 'channel-details-edit');
    await click(host, 'channel-details-cancel');

    expect(
        host.querySelector('[data-test="channel-details-topic"]'),
    ).toBeNull();
    expect(
        host.querySelector('[data-test="channel-details-edit"]'),
    ).not.toBeNull();
});
