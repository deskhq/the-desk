// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, h, nextTick } from 'vue';
import { translate } from '@/lib/i18n';
import type { Team } from '@/types';

/**
 * Covers the bot detail page's header and its API-tokens rack — the list, the
 * usage stamp and scope chips each row composes, the dialog that mints a token
 * and the one that revokes it. What is pinned here is the rendered markup and
 * the request each form makes: every selector, every guard deciding whether a
 * piece renders at all. The page is mounted whole, so the same expectations
 * hold before and after its sections move into components (#987).
 */
type FormFields = Record<string, unknown>;

type RecordedForm = {
    fields: string[];
    form: FormFields & { errors: Record<string, string>; processing: boolean };
};

type RecordedRequest = {
    fields: string[];
    url: string;
    options: {
        onSuccess?: () => void;
        onFinish?: () => void;
        preserveScroll?: boolean;
    };
};

const inertia = vi.hoisted(() => ({
    pageProps: { auth: { user: { id: 'me', timezone: 'UTC' } } },
    forms: [] as RecordedForm[],
    posts: [] as RecordedRequest[],
    deletes: [] as RecordedRequest[],
}));

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, reactive } = await import('vue');

    return {
        Head: defineComponent({ name: 'HeadStub', setup: () => () => null }),
        router: { patch: () => {} },
        usePage: () => ({ props: inertia.pageProps }),
        useForm: (initial: Record<string, unknown>) => {
            const fields = Object.keys(initial);
            const form = reactive({
                ...structuredClone(initial),
                errors: {} as Record<string, string>,
                processing: false,
                post(url: string, options: RecordedRequest['options'] = {}) {
                    inertia.posts.push({ fields, url, options });
                },
                delete(url: string, options: RecordedRequest['options'] = {}) {
                    inertia.deletes.push({ fields, url, options });
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

import Bot from './Bot.vue';

const team = {
    id: 't1',
    name: 'Acme',
    slug: 'acme',
    isPersonal: false,
    membersCount: 3,
    unreadCount: 0,
    mentionCount: 0,
} as Team;

const bot: App.Data.BotData = {
    id: 'b1',
    name: 'Deploy Bot',
    channelsCount: 3,
    tokensCount: 2,
    createdBy: null,
    lastPostedAt: null,
};

function token(
    overrides: Partial<App.Data.BotTokenData> = {},
): App.Data.BotTokenData {
    return {
        id: 'tok1',
        name: 'ci-pipeline',
        abilities: ['messages:write'],
        lastUsedAt: null,
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
            h(Bot, {
                team,
                bot,
                tokens: [],
                scopeOptions: [],
                channels: [],
                addableChannels: [],
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

/**
 * Marks every recorded form in flight. The confirmation forms are declared
 * with no fields at all, so there is nothing to tell them apart by — and a
 * button that disables on the wrong form's `processing` would still be wrong
 * against the request it fires, which each test asserts separately.
 */
function markProcessing(): void {
    for (const { form } of inertia.forms) {
        form.processing = true;
    }
}

beforeEach(() => {
    inertia.forms = [];
    inertia.posts = [];
    inertia.deletes = [];
});

afterEach(() => {
    for (const { app, host } of active) {
        app.unmount();
        host.remove();
    }

    active = [];
});

describe('the page header', () => {
    it('names the bot, badges it as one, and counts what it holds', () => {
        const host = mount();

        expect(host.textContent).toContain('Deploy Bot');
        expect(host.textContent).toContain('Bot');
        expect(host.textContent?.replace(/\s+/g, ' ')).toContain(
            '3 channels · 2 tokens',
        );
    });
});

describe('the tokens rack', () => {
    it('says so when the bot has no tokens', () => {
        const host = mount();

        expect(text(host, 'tokens-empty')).toBe('No tokens yet.');
        expect(host.querySelector('[data-test^="token-row-"]')).toBeNull();
    });

    it('renders a row per token with its name and granted scopes', () => {
        const host = mount({
            tokens: [token({ abilities: ['messages:write', 'channels:read'] })],
        });

        expect(text(host, 'token-row-tok1')).toContain('ci-pipeline');
        expect(text(host, 'token-row-tok1')).toContain('messages:write');
        expect(text(host, 'token-row-tok1')).toContain('channels:read');
        expect(host.querySelector('[data-test="tokens-empty"]')).toBeNull();
    });

    it('stamps the last use, or says the token has never been used', () => {
        const host = mount({
            tokens: [
                token(),
                token({ id: 'tok2', lastUsedAt: '2024-03-04T10:00:00.000Z' }),
            ],
        });

        expect(text(host, 'token-row-tok1')).toContain('never used');
        expect(text(host, 'token-row-tok2')).toContain('last used Mar 4');
    });

    it('offers a revoke control on every row', () => {
        const host = mount({ tokens: [token()] });

        expect(find(host, 'revoke-token-tok1')).not.toBeNull();
    });
});

describe('the new token dialog', () => {
    const scopeOptions = [
        { value: 'messages:write', label: 'Post messages' },
        { value: 'channels:read', label: 'Read channels' },
    ];

    /** Open the creation dialog and hand back its content element. */
    async function openDialog(host: HTMLElement): Promise<HTMLElement> {
        find(host, 'new-token-button')?.click();
        await nextTick();

        return find(host, 'new-token-dialog')!;
    }

    it('stays closed until the rack button is pressed', async () => {
        const host = mount();

        expect(find(host, 'new-token-dialog')).toBeNull();
        expect(await openDialog(host)).not.toBeNull();
    });

    it('offers a checkbox per scope, naming it and describing it', async () => {
        const host = mount({ scopeOptions });
        await openDialog(host);

        expect(text(host, 'scope-messages:write')).toContain('messages:write');
        expect(text(host, 'scope-messages:write')).toContain('Post messages');
        expect(find(host, 'scope-channels:read')).not.toBeNull();
    });

    it('adds a scope on the first click and drops it on the second', async () => {
        const host = mount({ scopeOptions });
        await openDialog(host);

        const checkbox = find(host, 'scope-messages:write')?.querySelector(
            '[data-slot="checkbox"]',
        ) as HTMLElement;

        checkbox.click();
        await nextTick();
        expect(formFor('name', 'abilities').abilities).toEqual([
            'messages:write',
        ]);

        checkbox.click();
        await nextTick();
        expect(formFor('name', 'abilities').abilities).toEqual([]);
    });

    it('posts the token without losing the scroll, then closes and clears itself', async () => {
        const host = mount({ scopeOptions });
        await openDialog(host);

        const input = find(host, 'token-name-input') as HTMLInputElement;
        input.value = 'release-bot';
        input.dispatchEvent(new Event('input'));
        await nextTick();

        find(host, 'token-create-button')?.click();
        await nextTick();

        const post = inertia.posts.at(-1)!;
        expect(post.url).toBe(
            '/settings/teams/acme/integrations/bots/b1/tokens',
        );
        expect(post.options.preserveScroll).toBe(true);
        expect(formFor('name', 'abilities').name).toBe('release-bot');

        post.options.onSuccess?.();
        await nextTick();

        expect(find(host, 'new-token-dialog')).toBeNull();
        expect(formFor('name', 'abilities').name).toBe('');
    });

    it('holds the submit button while the request is in flight', async () => {
        const host = mount();
        await openDialog(host);

        markProcessing();
        await nextTick();

        expect(find(host, 'token-create-button')).toHaveProperty(
            'disabled',
            true,
        );
    });

    it('shows the errors the server sent back for the name and the scopes', async () => {
        const host = mount({ scopeOptions });
        const dialog = await openDialog(host);

        Object.assign(formFor('name', 'abilities').errors, {
            name: 'The name field is required.',
            abilities: 'Pick at least one scope.',
        });
        await nextTick();

        expect(dialog.textContent).toContain('The name field is required.');
        expect(dialog.textContent).toContain('Pick at least one scope.');
    });
});

describe('the revoke token dialog', () => {
    /** Open the revoke dialog for the seeded token. */
    async function openDialog(host: HTMLElement): Promise<HTMLElement> {
        find(host, 'revoke-token-tok1')?.click();
        await nextTick();

        return find(host, 'revoke-token-dialog')!;
    }

    it('stays closed until a row asks to revoke, and names that token', async () => {
        const host = mount({ tokens: [token()] });

        expect(find(host, 'revoke-token-dialog')).toBeNull();
        expect((await openDialog(host)).textContent).toContain(
            'Revoke ci-pipeline?',
        );
    });

    it('deletes the token without losing the scroll, then closes itself', async () => {
        const host = mount({ tokens: [token()] });
        await openDialog(host);

        find(host, 'revoke-token-confirm')?.click();
        await nextTick();

        const request = inertia.deletes.at(-1)!;
        expect(request.url).toBe(
            '/settings/teams/acme/integrations/bots/b1/tokens/tok1',
        );
        expect(request.options.preserveScroll).toBe(true);

        request.options.onFinish?.();
        await nextTick();

        expect(find(host, 'revoke-token-dialog')).toBeNull();
    });

    it('holds the confirm button while the request is in flight', async () => {
        const host = mount({ tokens: [token()] });
        await openDialog(host);

        markProcessing();
        await nextTick();

        expect(find(host, 'revoke-token-confirm')).toHaveProperty(
            'disabled',
            true,
        );
    });
});
