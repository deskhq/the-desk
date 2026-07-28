// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, h, nextTick } from 'vue';
import { translate } from '@/lib/i18n';
import type { Team } from '@/types';

/**
 * Covers the integrations home's page header and its bots rack — the list, the
 * creator and last-post lines each row composes, and the dialog that mints a
 * bot. What is pinned here is the rendered markup and the request the form
 * makes: every selector, every guard deciding whether a piece renders at all.
 * The page is mounted whole, so the same expectations hold before and after its
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

function bot(overrides: Partial<App.Data.BotData> = {}): App.Data.BotData {
    return {
        id: 'b1',
        name: 'Deploy Bot',
        channelsCount: 3,
        tokensCount: 2,
        createdBy: { id: 'me', name: 'Ada' } as App.Data.MentionData,
        lastPostedAt: null,
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

beforeEach(() => {
    inertia.forms = [];
    inertia.posts = [];
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2024-03-06T10:00:00.000Z'));
});

afterEach(() => {
    vi.useRealTimers();

    for (const { app, host } of active) {
        app.unmount();
        host.remove();
    }

    active = [];
});

describe('the page header', () => {
    it('names the workspace the integrations belong to', () => {
        const host = mount();

        expect(host.textContent).toContain(
            'Bots, API access, and webhooks for Acme',
        );
    });

    it('links out to the public API reference in a new tab', () => {
        const host = mount();
        const link = find(host, 'api-docs-link');

        expect(link?.getAttribute('href')).toBe(
            'https://docs.thedeskhq.app/reference/api/',
        );
        expect(link?.getAttribute('target')).toBe('_blank');
        expect(link?.getAttribute('rel')).toBe('noopener noreferrer');
    });
});

describe('the bots rack', () => {
    it('says so when the workspace has no bots', () => {
        const host = mount();

        expect(text(host, 'bots-empty')).toBe('No bots yet.');
        expect(host.querySelector('[data-test^="bot-row-"]')).toBeNull();
    });

    it('renders a row per bot with its channel, token and creator counts', () => {
        const host = mount({ bots: [bot()] });

        expect(text(host, 'bot-row-b1')).toContain('Deploy Bot');
        expect(text(host, 'bot-row-b1')).toContain(
            '3 channels · 2 tokens · created by you',
        );
        expect(host.querySelector('[data-test="bots-empty"]')).toBeNull();
    });

    it('credits another member by name, and says so when the creator is gone', () => {
        const host = mount({
            bots: [
                bot({
                    createdBy: {
                        id: 'peer',
                        name: 'Grace',
                    } as App.Data.MentionData,
                }),
                bot({ id: 'b2', createdBy: null }),
            ],
        });

        expect(text(host, 'bot-row-b1')).toContain('created by Grace');
        expect(text(host, 'bot-row-b2')).toContain('Unknown creator');
    });

    it('stamps the last post, or says the bot has never posted', () => {
        const host = mount({
            bots: [
                bot(),
                bot({ id: 'b2', lastPostedAt: '2024-03-04T10:00:00.000Z' }),
            ],
        });

        expect(text(host, 'bot-row-b1')).toContain('never posted');
        expect(text(host, 'bot-row-b2')).toContain('last post 2 days ago');
    });

    it('points each row at its own management page', () => {
        const host = mount({ bots: [bot()] });

        expect(find(host, 'manage-bot-b1')?.getAttribute('href')).toBe(
            '/settings/teams/acme/integrations/bots/b1',
        );
    });
});

describe('the new bot dialog', () => {
    it('stays closed until the rack button is pressed', async () => {
        const host = mount();

        expect(find(host, 'new-bot-dialog')).toBeNull();

        find(host, 'new-bot-button')?.click();
        await nextTick();

        expect(find(host, 'new-bot-dialog')).not.toBeNull();
    });

    it('posts the typed name to the team, then closes and clears itself', async () => {
        const host = mount();

        find(host, 'new-bot-button')?.click();
        await nextTick();

        const input = find(host, 'bot-name-input') as HTMLInputElement;
        input.value = 'Release Bot';
        input.dispatchEvent(new Event('input'));
        await nextTick();

        find(host, 'bot-create-button')?.click();
        await nextTick();

        const post = inertia.posts.at(-1)!;
        expect(post.url).toBe('/settings/teams/acme/integrations/bots');
        expect(formFor('name').name).toBe('Release Bot');

        post.options.onSuccess?.();
        await nextTick();

        expect(find(host, 'new-bot-dialog')).toBeNull();
        expect(formFor('name').name).toBe('');
    });

    it('holds the submit button while the request is in flight', async () => {
        const host = mount();

        find(host, 'new-bot-button')?.click();
        await nextTick();

        formFor('name').processing = true;
        await nextTick();

        expect(find(host, 'bot-create-button')).toHaveProperty(
            'disabled',
            true,
        );
    });

    it('shows the name error the server sent back', async () => {
        const host = mount();

        find(host, 'new-bot-button')?.click();
        await nextTick();

        formFor('name').errors.name = 'The name field is required.';
        await nextTick();

        expect(find(host, 'new-bot-dialog')?.textContent).toContain(
            'The name field is required.',
        );
    });
});
