// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, h, nextTick } from 'vue';
import { translate } from '@/lib/i18n';
import type { Team } from '@/types';

/**
 * Covers the bot detail page's channels rack — the membership list, the dialog
 * that adds the bot to a channel and the one that takes it out — and the
 * danger zone that deletes the bot. What is pinned here is the rendered markup
 * and the request each form makes: every selector, every guard deciding
 * whether a piece renders at all. The page is mounted whole, so the same
 * expectations hold before and after its sections move into components (#987).
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

/**
 * The real select portals its list and only builds the options once opened,
 * neither of which jsdom can drive. The stub keeps the parts the page owns —
 * the option per channel, the placeholder, and the value a pick writes to the
 * form — and drops the overlay mechanics reka is responsible for.
 */
vi.mock('@/components/ui/select', async () => {
    const { defineComponent, h, inject, provide } = await import('vue');
    const PICK = Symbol.for('select-stub-pick');

    const passthrough = (name: string) =>
        defineComponent({
            name,
            setup:
                (_props, { slots }) =>
                () =>
                    h('div', { 'data-stub': name }, slots.default?.()),
        });

    return {
        Select: defineComponent({
            name: 'SelectStub',
            props: { modelValue: { type: String, default: '' } },
            emits: ['update:modelValue'],
            setup(_props, { slots, emit }) {
                provide(PICK, (value: string) =>
                    emit('update:modelValue', value),
                );

                return () =>
                    h('div', { 'data-stub': 'Select' }, slots.default?.());
            },
        }),
        SelectContent: passthrough('SelectContent'),
        SelectItem: defineComponent({
            name: 'SelectItemStub',
            props: { value: { type: String, required: true } },
            setup(props, { slots }) {
                const pick = inject<(value: string) => void>(PICK);

                return () =>
                    h(
                        'button',
                        { type: 'button', onClick: () => pick?.(props.value) },
                        slots.default?.(),
                    );
            },
        }),
        SelectTrigger: passthrough('SelectTrigger'),
        SelectValue: defineComponent({
            name: 'SelectValueStub',
            props: { placeholder: { type: String, default: '' } },
            setup: (props) => () => h('span', props.placeholder),
        }),
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

type ChannelMembership = {
    id: string;
    name: string;
    visibility: 'public' | 'private';
};

function channel(
    overrides: Partial<ChannelMembership> = {},
): ChannelMembership {
    return { id: 'c1', name: 'ops', visibility: 'public', ...overrides };
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

describe('the channels rack', () => {
    it('says so when the bot is in no channel yet', () => {
        const host = mount();

        expect(text(host, 'channels-empty')).toBe(
            'Not in any channel yet. Add it to a channel so it can post there.',
        );
        expect(host.querySelector('[data-test^="channel-row-"]')).toBeNull();
    });

    it('renders a row per membership, marking public and private channels apart', () => {
        const host = mount({
            channels: [
                channel(),
                channel({ id: 'c2', name: 'secrets', visibility: 'private' }),
            ],
        });

        expect(text(host, 'channel-row-c1')).toContain('ops');
        expect(text(host, 'channel-row-c1')).toContain('Public channel');
        expect(text(host, 'channel-row-c2')).toContain('Private channel');
        expect(host.querySelector('[data-test="channels-empty"]')).toBeNull();
    });

    it('offers a remove control on every row', () => {
        const host = mount({ channels: [channel()] });

        expect(find(host, 'remove-channel-c1')).not.toBeNull();
    });

    it('refuses to add the bot when it is already in every channel', () => {
        expect(find(mount(), 'add-channel-button')).toHaveProperty(
            'disabled',
            true,
        );
        expect(
            find(mount({ addableChannels: [channel()] }), 'add-channel-button'),
        ).toHaveProperty('disabled', false);
    });
});

describe('the add to channel dialog', () => {
    /** Open the add dialog and hand back its content element. */
    async function openDialog(host: HTMLElement): Promise<HTMLElement> {
        find(host, 'add-channel-button')?.click();
        await nextTick();

        return find(host, 'add-channel-dialog')!;
    }

    it('stays closed until the rack button is pressed', async () => {
        const host = mount({ addableChannels: [channel()] });

        expect(find(host, 'add-channel-dialog')).toBeNull();
        expect(await openDialog(host)).not.toBeNull();
    });

    it('offers an option per addable channel behind a placeholder', async () => {
        const host = mount({
            addableChannels: [
                channel(),
                channel({ id: 'c2', name: 'secrets', visibility: 'private' }),
            ],
        });
        const dialog = await openDialog(host);

        expect(dialog.textContent).toContain('Select a channel');
        expect(text(host, 'add-channel-option-c1')).toContain('ops');
        expect(text(host, 'add-channel-option-c2')).toContain('secrets');
    });

    it('records the picked channel on the form and releases the submit', async () => {
        const host = mount({ addableChannels: [channel()] });
        await openDialog(host);

        expect(find(host, 'add-channel-submit')).toHaveProperty(
            'disabled',
            true,
        );

        find(host, 'add-channel-option-c1')?.click();
        await nextTick();

        expect(formFor('channel_id').channel_id).toBe('c1');
        expect(find(host, 'add-channel-submit')).toHaveProperty(
            'disabled',
            false,
        );
    });

    it('posts the membership without losing the scroll, then closes and clears itself', async () => {
        const host = mount({ addableChannels: [channel()] });
        await openDialog(host);

        find(host, 'add-channel-option-c1')?.click();
        await nextTick();

        find(host, 'add-channel-submit')?.click();
        await nextTick();

        const post = inertia.posts.at(-1)!;
        expect(post.url).toBe(
            '/settings/teams/acme/integrations/bots/b1/channels',
        );
        expect(post.options.preserveScroll).toBe(true);
        expect(formFor('channel_id').channel_id).toBe('c1');

        post.options.onSuccess?.();
        await nextTick();

        expect(find(host, 'add-channel-dialog')).toBeNull();
        expect(formFor('channel_id').channel_id).toBe('');
    });

    it('shows the channel error the server sent back', async () => {
        const host = mount({ addableChannels: [channel()] });
        const dialog = await openDialog(host);

        formFor('channel_id').errors.channel_id = 'Pick a channel.';
        await nextTick();

        expect(dialog.textContent).toContain('Pick a channel.');
    });
});

describe('the remove from channel dialog', () => {
    /** Open the removal dialog for the seeded membership. */
    async function openDialog(host: HTMLElement): Promise<HTMLElement> {
        find(host, 'remove-channel-c1')?.click();
        await nextTick();

        return find(host, 'remove-channel-dialog')!;
    }

    it('stays closed until a row asks to remove, and names both sides', async () => {
        const host = mount({ channels: [channel()] });

        expect(find(host, 'remove-channel-dialog')).toBeNull();
        expect((await openDialog(host)).textContent).toContain(
            'Remove Deploy Bot from ops?',
        );
    });

    it('deletes the membership without losing the scroll, then closes itself', async () => {
        const host = mount({ channels: [channel()] });
        await openDialog(host);

        find(host, 'remove-channel-confirm')?.click();
        await nextTick();

        const request = inertia.deletes.at(-1)!;
        expect(request.url).toBe(
            '/settings/teams/acme/integrations/bots/b1/channels/c1',
        );
        expect(request.options.preserveScroll).toBe(true);

        request.options.onFinish?.();
        await nextTick();

        expect(find(host, 'remove-channel-dialog')).toBeNull();
    });

    it('holds the confirm button while the request is in flight', async () => {
        const host = mount({ channels: [channel()] });
        await openDialog(host);

        markProcessing();
        await nextTick();

        expect(find(host, 'remove-channel-confirm')).toHaveProperty(
            'disabled',
            true,
        );
    });
});

describe('the danger zone', () => {
    /** Open the deletion dialog and hand back its content element. */
    async function openDialog(host: HTMLElement): Promise<HTMLElement> {
        find(host, 'delete-bot-button')?.click();
        await nextTick();

        return find(host, 'delete-bot-dialog')!;
    }

    it('stays closed until the danger zone asks, and names the bot', async () => {
        const host = mount();

        expect(find(host, 'delete-bot-dialog')).toBeNull();
        expect((await openDialog(host)).textContent).toContain(
            'Delete Deploy Bot?',
        );
    });

    it('deletes the bot, leaving the page rather than preserving the scroll', async () => {
        const host = mount();
        await openDialog(host);

        find(host, 'delete-bot-confirm')?.click();
        await nextTick();

        const request = inertia.deletes.at(-1)!;
        expect(request.url).toBe('/settings/teams/acme/integrations/bots/b1');
        expect(request.options.preserveScroll).toBeUndefined();
    });

    it('holds the confirm button while the request is in flight', async () => {
        const host = mount();
        await openDialog(host);

        markProcessing();
        await nextTick();

        expect(find(host, 'delete-bot-confirm')).toHaveProperty(
            'disabled',
            true,
        );
    });
});
