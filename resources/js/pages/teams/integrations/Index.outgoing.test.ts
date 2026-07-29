// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, h, nextTick } from 'vue';
import { translate } from '@/lib/i18n';
import type { Team } from '@/types';

/**
 * Covers the integrations home's outgoing-webhooks rack: the list, the health
 * each row reports, and the dialog that subscribes an endpoint — the event and
 * channel checkboxes it toggles, and the request it makes. The page is mounted
 * whole, so the same expectations hold before and after its sections move into
 * components (#985).
 */
type FormFields = Record<string, unknown>;

type RecordedForm = {
    fields: string[];
    form: FormFields & { errors: Record<string, string>; processing: boolean };
};

type RecordedPost = {
    fields: string[];
    url: string;
    options: { onSuccess?: () => void; preserveScroll?: boolean };
};

const inertia = vi.hoisted(() => ({
    pageProps: { auth: { user: { id: 'me', timezone: 'UTC' } } },
    forms: [] as RecordedForm[],
    posts: [] as RecordedPost[],
}));

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h, reactive } = await import('vue');

    return {
        Head: defineComponent({ name: 'HeadStub', setup: () => () => null }),
        Link: defineComponent({
            name: 'LinkStub',
            props: { href: { type: [String, Object], default: '' } },
            setup:
                (props, { slots }) =>
                () =>
                    h(
                        'a',
                        {
                            href:
                                typeof props.href === 'string'
                                    ? props.href
                                    : (props.href as { url: string }).url,
                        },
                        slots.default?.(),
                    ),
        }),
        router: { patch: () => {} },
        usePage: () => ({ props: inertia.pageProps }),
        useForm: (initial: Record<string, unknown>) => {
            const fields = Object.keys(initial);
            const form = reactive({
                ...structuredClone(initial),
                errors: {} as Record<string, string>,
                processing: false,
                post(url: string, options: RecordedPost['options'] = {}) {
                    inertia.posts.push({ fields, url, options });
                },
                reset() {
                    Object.assign(form, structuredClone(initial));
                },
            });

            inertia.forms.push({ fields, form: form as RecordedForm['form'] });

            return form;
        },
    };
});

vi.mock('@/components/integrations/RevealSecretDialog.vue', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        default: defineComponent({
            name: 'RevealSecretDialogStub',
            setup: () => () => h('div', { 'data-stub': 'RevealSecretDialog' }),
        }),
    };
});

vi.mock('@/components/ui/dialog', async () => {
    const { defineComponent, h } = await import('vue');

    const passthrough = (name: string) =>
        defineComponent({
            name,
            setup:
                (_props, { slots }) =>
                () =>
                    h('div', { 'data-stub': name }, slots.default?.()),
        });

    return {
        // Renders its content only while open, so a closed dialog is observably
        // gone from the DOM the way the real overlay is.
        Dialog: defineComponent({
            name: 'DialogStub',
            props: { open: { type: Boolean, default: false } },
            emits: ['update:open'],
            setup:
                (props, { slots }) =>
                () =>
                    props.open
                        ? h('div', { 'data-stub': 'Dialog' }, slots.default?.())
                        : null,
        }),
        DialogClose: passthrough('DialogClose'),
        DialogContent: passthrough('DialogContent'),
        DialogDescription: passthrough('DialogDescription'),
        DialogFooter: passthrough('DialogFooter'),
        DialogHeader: passthrough('DialogHeader'),
        DialogTitle: passthrough('DialogTitle'),
    };
});

import Index from './Index.vue';

const team = {
    id: 't1',
    name: 'Acme',
    slug: 'acme',
    isPersonal: false,
    membersCount: 3,
    unreadCount: 0,
    mentionCount: 0,
} as Team;

const eventOptions = [
    { value: 'message.created', label: 'A message is posted' },
    { value: 'channel.created', label: 'A channel is created' },
];

function subscription(
    overrides: Partial<App.Data.WebhookSubscriptionData> = {},
): App.Data.WebhookSubscriptionData {
    return {
        id: 's1',
        name: 'Ops mirror',
        url: 'https://ops.example.com/desk',
        events: ['message.created', 'channel.created'],
        status: 'active',
        disabledAt: null,
        lastSuccessAt: null,
        createdAt: '2024-03-01T10:00:00.000Z',
        ...overrides,
    };
}

let active: Array<{ app: App; host: HTMLElement }> = [];

function mount(props: Record<string, unknown> = {}): HTMLElement {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp({
        render: () =>
            h(Index, {
                team,
                bots: [],
                incomingWebhooks: [],
                outgoingWebhooks: [],
                channels: [],
                scopeOptions: [],
                eventOptions,
                ...props,
            }),
    });
    app.config.globalProperties.$t = translate;
    app.mount(host);
    active.push({ app, host });

    return host;
}

function find(host: HTMLElement, selector: string): HTMLElement | null {
    return host.querySelector<HTMLElement>(`[data-test="${selector}"]`);
}

function text(host: HTMLElement, selector: string): string {
    return find(host, selector)?.textContent?.replace(/\s+/g, ' ').trim() ?? '';
}

/** The recorded form created with exactly these fields. */
function formFor(...fields: string[]): RecordedForm['form'] {
    const match = inertia.forms.find(
        ({ fields: own }) =>
            own.length === fields.length &&
            fields.every((field) => own.includes(field)),
    );

    expect(match, `no form declared ${fields.join(', ')}`).toBeDefined();

    return match!.form;
}

/** The outgoing form, named by the fields it is built from. */
function outgoingForm(): RecordedForm['form'] {
    return formFor('name', 'url', 'events', 'channel_ids');
}

/** Open the creation dialog and hand back its content element. */
async function openDialog(host: HTMLElement): Promise<HTMLElement> {
    find(host, 'new-outgoing-button')?.click();
    await nextTick();

    return find(host, 'new-outgoing-dialog')!;
}

/** Click the checkbox inside a labelled row. */
async function check(host: HTMLElement, selector: string): Promise<void> {
    find(host, selector)
        ?.querySelector<HTMLElement>('[data-slot="checkbox"]')
        ?.click();
    await nextTick();
}

beforeEach(() => {
    inertia.forms = [];
    inertia.posts = [];
});

afterEach(() => {
    for (const { app, host } of active) {
        app.unmount();
        host.remove();
    }

    active = [];
});

describe('the outgoing webhooks rack', () => {
    it('says so when the workspace has no subscriptions', () => {
        const host = mount();

        expect(text(host, 'outgoing-empty')).toBe(
            'No outgoing subscriptions yet.',
        );
        expect(host.querySelector('[data-test^="outgoing-row-"]')).toBeNull();
    });

    it('counts the events on a subscription and names the host they go to', () => {
        const host = mount({ outgoingWebhooks: [subscription()] });

        expect(text(host, 'outgoing-row-s1')).toContain('Ops mirror');
        expect(text(host, 'outgoing-row-s1')).toContain(
            '2 events → ops.example.com',
        );
    });

    it('falls back to the raw endpoint when it cannot be parsed as a URL', () => {
        const host = mount({
            outgoingWebhooks: [subscription({ url: 'not a url' })],
        });

        expect(text(host, 'outgoing-row-s1')).toContain('2 events → not a url');
    });

    it('reports a healthy subscription as active and a stopped one as auto-disabled', () => {
        const host = mount({
            outgoingWebhooks: [
                subscription(),
                subscription({
                    id: 's2',
                    status: 'disabled',
                    disabledAt: '2024-03-02T10:00:00.000Z',
                }),
            ],
        });

        expect(text(host, 'outgoing-row-s1')).toContain('Active');
        expect(text(host, 'outgoing-row-s2')).toContain('Auto-disabled');
        expect(text(host, 'outgoing-row-s2')).not.toContain('Active');
    });

    it('points each row at its own management page', () => {
        const host = mount({ outgoingWebhooks: [subscription()] });

        expect(find(host, 'manage-outgoing-s1')?.getAttribute('href')).toBe(
            '/settings/teams/acme/integrations/webhooks/s1',
        );
    });
});

describe('the new outgoing subscription dialog', () => {
    it('stays closed until the rack button is pressed', async () => {
        const host = mount();

        expect(find(host, 'new-outgoing-dialog')).toBeNull();
        expect(await openDialog(host)).not.toBeNull();
    });

    it('subscribes to an event on the first click and unsubscribes on the second', async () => {
        const host = mount();
        await openDialog(host);

        await check(host, 'outgoing-event-message.created');
        expect(outgoingForm().events).toEqual(['message.created']);

        await check(host, 'outgoing-event-channel.created');
        expect(outgoingForm().events).toEqual([
            'message.created',
            'channel.created',
        ]);

        await check(host, 'outgoing-event-message.created');
        expect(outgoingForm().events).toEqual(['channel.created']);
    });

    it('hides the channel choice when the workspace has no channels', async () => {
        const host = mount();
        await openDialog(host);

        expect(host.textContent).not.toContain(
            'Leave empty to receive events from every channel.',
        );
        expect(
            host.querySelector('[data-test^="outgoing-channel-"]'),
        ).toBeNull();
    });

    it('scopes the subscription to the channels picked, or to all of them', async () => {
        const host = mount({ channels: [{ id: 'c1', name: 'ops' }] });
        await openDialog(host);

        expect(host.textContent).toContain(
            'Leave empty to receive events from every channel.',
        );
        expect(outgoingForm().channel_ids).toEqual([]);

        await check(host, 'outgoing-channel-c1');
        expect(outgoingForm().channel_ids).toEqual(['c1']);
    });

    it('posts the subscription without losing the scroll, then closes and clears itself', async () => {
        const host = mount();
        await openDialog(host);

        const url = find(host, 'outgoing-url-input') as HTMLInputElement;
        url.value = 'https://ops.example.com/desk';
        url.dispatchEvent(new Event('input'));
        await check(host, 'outgoing-event-message.created');

        find(host, 'outgoing-create-button')?.click();
        await nextTick();

        const post = inertia.posts.at(-1)!;
        expect(post.url).toBe('/settings/teams/acme/integrations/webhooks');
        expect(post.options.preserveScroll).toBe(true);
        expect(outgoingForm().url).toBe('https://ops.example.com/desk');

        post.options.onSuccess?.();
        await nextTick();

        expect(find(host, 'new-outgoing-dialog')).toBeNull();
        expect(outgoingForm().url).toBe('');
        expect(outgoingForm().events).toEqual([]);
    });

    it('shows the errors the server sent back, fieldsets included', async () => {
        const host = mount({ channels: [{ id: 'c1', name: 'ops' }] });
        const dialog = await openDialog(host);

        Object.assign(outgoingForm().errors, {
            url: 'The endpoint must be https.',
            events: 'Pick at least one event.',
            channel_ids: 'That channel is not yours.',
        });
        await nextTick();

        expect(dialog.textContent).toContain('The endpoint must be https.');
        expect(dialog.textContent).toContain('Pick at least one event.');
        expect(dialog.textContent).toContain('That channel is not yours.');
    });
});
