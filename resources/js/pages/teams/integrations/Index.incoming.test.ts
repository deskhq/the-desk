// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, h, nextTick } from 'vue';
import { translate } from '@/lib/i18n';
import type { Team } from '@/types';

/**
 * Covers the integrations home's incoming-webhooks rack: the list and the
 * revoke control each row hands off to, and the dialog that mints a hook —
 * its two selects, the signing-secret opt-in, and the request it makes. The
 * page is mounted whole, so the same expectations hold before and after its
 * sections move into components (#985).
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
    url: '/settings/teams/acme/integrations',
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
        usePage: () => ({ props: inertia.pageProps, url: inertia.url }),
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

vi.mock('@/components/integrations/IncomingWebhookRevoke.vue', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        default: defineComponent({
            name: 'IncomingWebhookRevokeStub',
            props: {
                team: { type: String, required: true },
                webhook: {
                    type: Object as () => App.Data.IncomingWebhookData,
                    required: true,
                },
            },
            setup: (props) => () =>
                h('div', {
                    'data-stub': 'IncomingWebhookRevoke',
                    'data-team': props.team,
                    'data-webhook': props.webhook.id,
                }),
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

const bots: App.Data.BotData[] = [
    {
        id: 'b1',
        name: 'Deploy Bot',
        channelsCount: 1,
        tokensCount: 0,
        createdBy: null,
        lastPostedAt: null,
    },
];

function webhook(
    overrides: Partial<App.Data.IncomingWebhookData> = {},
): App.Data.IncomingWebhookData {
    return {
        id: 'w1',
        name: 'CI alerts',
        channelName: 'ops',
        botName: 'Deploy Bot',
        active: true,
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
                bots,
                incomingWebhooks: [],
                outgoingWebhooks: [],
                channels: [],
                scopeOptions: [],
                eventOptions: [],
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

/** The incoming form, named by the fields it is built from. */
function incomingForm(): RecordedForm['form'] {
    return formFor('name', 'channel_id', 'bot_id', 'with_signing_secret');
}

/** Open the creation dialog and hand back its content element. */
async function openDialog(host: HTMLElement): Promise<HTMLElement> {
    find(host, 'new-incoming-button')?.click();
    await nextTick();

    return find(host, 'new-incoming-dialog')!;
}

beforeEach(() => {
    inertia.forms = [];
    inertia.posts = [];
    inertia.url = '/settings/teams/acme/integrations';
});

afterEach(() => {
    for (const { app, host } of active) {
        app.unmount();
        host.remove();
    }

    active = [];
});

describe('the incoming webhooks rack', () => {
    it('says so when the workspace has no incoming webhooks', () => {
        const host = mount();

        expect(text(host, 'incoming-empty')).toBe('No incoming webhooks yet.');
        expect(host.querySelector('[data-test^="incoming-row-"]')).toBeNull();
    });

    it('renders a row per hook naming the channel and the bot it posts as', () => {
        const host = mount({ incomingWebhooks: [webhook()] });

        expect(text(host, 'incoming-row-w1')).toContain('CI alerts');
        expect(text(host, 'incoming-row-w1')).toContain(
            'posts to #ops as Deploy Bot',
        );
        expect(text(host, 'incoming-row-w1')).toContain('Active');
        expect(host.querySelector('[data-test="incoming-empty"]')).toBeNull();
    });

    it('singles out the hook a message sent the admin here to revoke', () => {
        inertia.url = '/settings/teams/acme/integrations?webhook=w2';

        const host = mount({
            incomingWebhooks: [webhook(), webhook({ id: 'w2' })],
        });

        expect(find(host, 'incoming-row-w2')?.dataset.highlighted).toBe('true');
        expect(
            find(host, 'incoming-row-w1')?.dataset.highlighted,
        ).toBeUndefined();
    });

    it('singles out nothing when the page was opened on its own', () => {
        const host = mount({ incomingWebhooks: [webhook()] });

        expect(
            find(host, 'incoming-row-w1')?.dataset.highlighted,
        ).toBeUndefined();
    });

    it('hands each row its own revoke control, scoped to the team', () => {
        const host = mount({ incomingWebhooks: [webhook()] });
        const revoke = find(host, 'incoming-row-w1')?.querySelector(
            '[data-stub="IncomingWebhookRevoke"]',
        );

        expect(revoke?.getAttribute('data-team')).toBe('acme');
        expect(revoke?.getAttribute('data-webhook')).toBe('w1');
    });

    it('refuses a new hook until the workspace has a bot to post as', () => {
        const withoutBots = mount({ bots: [] });

        expect(find(withoutBots, 'new-incoming-button')).toHaveProperty(
            'disabled',
            true,
        );
        expect(find(mount(), 'new-incoming-button')).toHaveProperty(
            'disabled',
            false,
        );
    });
});

describe('the new incoming webhook dialog', () => {
    it('stays closed until the rack button is pressed', async () => {
        const host = mount();

        expect(find(host, 'new-incoming-dialog')).toBeNull();
        expect(await openDialog(host)).not.toBeNull();
    });

    it('offers every channel and every bot behind a placeholder option', async () => {
        const host = mount({
            channels: [
                { id: 'c1', name: 'ops' },
                { id: 'c2', name: 'general' },
            ],
        });
        await openDialog(host);

        const channels = find(
            host,
            'incoming-channel-select',
        ) as HTMLSelectElement;
        const selectedBots = find(
            host,
            'incoming-bot-select',
        ) as HTMLSelectElement;

        expect(
            [...channels.options].map((option) => option.textContent?.trim()),
        ).toEqual(['Select a channel', '#ops', '#general']);
        expect(
            [...selectedBots.options].map((option) =>
                option.textContent?.trim(),
            ),
        ).toEqual(['Select a bot', 'Deploy Bot']);
        expect(channels.options[0].disabled).toBe(true);
    });

    it('records the picked channel and bot on the form', async () => {
        const host = mount({ channels: [{ id: 'c1', name: 'ops' }] });
        await openDialog(host);

        const channels = find(
            host,
            'incoming-channel-select',
        ) as HTMLSelectElement;
        channels.value = 'c1';
        channels.dispatchEvent(new Event('change'));
        await nextTick();

        expect(incomingForm().channel_id).toBe('c1');
    });

    it('opts into an HMAC signing secret from the checkbox', async () => {
        const host = mount();
        await openDialog(host);

        expect(incomingForm().with_signing_secret).toBe(false);

        find(host, 'incoming-signing-toggle')
            ?.querySelector<HTMLElement>('[data-slot="checkbox"]')
            ?.click();
        await nextTick();

        expect(incomingForm().with_signing_secret).toBe(true);
    });

    it('posts the hook without losing the scroll, then closes and clears itself', async () => {
        const host = mount({ channels: [{ id: 'c1', name: 'ops' }] });
        await openDialog(host);

        const input = find(host, 'incoming-name-input') as HTMLInputElement;
        input.value = 'CI alerts';
        input.dispatchEvent(new Event('input'));
        await nextTick();

        find(host, 'incoming-create-button')?.click();
        await nextTick();

        const post = inertia.posts.at(-1)!;
        expect(post.url).toBe(
            '/settings/teams/acme/integrations/incoming-webhooks',
        );
        expect(post.options.preserveScroll).toBe(true);
        expect(incomingForm().name).toBe('CI alerts');

        post.options.onSuccess?.();
        await nextTick();

        expect(find(host, 'new-incoming-dialog')).toBeNull();
        expect(incomingForm().name).toBe('');
    });

    it('shows the errors the server sent back for each field', async () => {
        const host = mount();
        const dialog = await openDialog(host);

        Object.assign(incomingForm().errors, {
            name: 'The name field is required.',
            channel_id: 'Pick a channel.',
            bot_id: 'Pick a bot.',
        });
        await nextTick();

        expect(dialog.textContent).toContain('The name field is required.');
        expect(dialog.textContent).toContain('Pick a channel.');
        expect(dialog.textContent).toContain('Pick a bot.');
    });
});
