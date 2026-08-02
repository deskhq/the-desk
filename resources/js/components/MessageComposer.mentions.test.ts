// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { App, Component } from 'vue';
import { createApp, defineComponent, h, nextTick, ref } from 'vue';
import type { Mention, PersonRef, RosterMember } from '@/types';
import MessageComposer from './MessageComposer.vue';

/**
 * Covers the composer's `@` autocomplete: which rows the menu offers (people
 * first, with reserved tail slots for user groups), how a chosen row completes
 * into a mention token, the keyboard model, and which mentions a send carries.
 *
 * Written against the live `<MessageComposer>` rather than a helper so it keeps
 * watching the same behaviour once the mention surface moves out of the file.
 */

vi.mock('@/actions/App/Http/Controllers/Channels/AttachmentController', () => ({
    store: () => ({ url: '/t/acme/c/general/attachments' }),
}));

// The workspace's mentionable groups ride on the Inertia page in the app; here
// they are handed straight to the composable the menu reads them through.
const groups: App.Data.UserGroupData[] = [
    {
        id: 'g1111111-1111-4111-8111-111111111111',
        name: 'Design',
        slug: 'design',
        membersCount: 4,
        members: [],
    },
    {
        id: 'g2222222-2222-4222-8222-222222222222',
        name: 'Platform',
        slug: 'platform',
        membersCount: 1,
        members: [],
    },
    {
        id: 'g3333333-3333-4333-8333-333333333333',
        name: 'Support',
        slug: 'support',
        membersCount: 7,
        members: [],
    },
    {
        id: 'g4444444-4444-4444-8444-444444444444',
        name: 'Growth',
        slug: 'growth',
        membersCount: 2,
        members: [],
    },
];

vi.mock('@/composables/useUserGroups', () => ({
    useUserGroups: () => ({
        groups: { value: groups },
        search: (query: string) =>
            query === ''
                ? groups
                : groups.filter(
                      (group) =>
                          group.slug.includes(query) ||
                          group.name.toLowerCase().includes(query),
                  ),
    }),
}));

vi.mock('@/components/ui/button', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Button: defineComponent({
            name: 'ButtonStub',
            inheritAttrs: false,
            setup:
                (_props, { attrs, slots }) =>
                () =>
                    h('button', attrs, slots.default?.()),
        }),
    };
});

vi.mock('@/components/ui/tooltip', async () => {
    const { defineComponent, h } = await import('vue');
    const slot = (name: string) =>
        defineComponent({
            name,
            setup:
                (_props, { slots }) =>
                () =>
                    h('div', slots.default?.()),
        });

    return {
        Tooltip: slot('TooltipStub'),
        TooltipContent: slot('TooltipContentStub'),
        TooltipProvider: slot('TooltipProviderStub'),
        TooltipTrigger: slot('TooltipTriggerStub'),
    };
});

const ADA = {
    id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    name: 'Ada Lovelace',
};
const GRACE = {
    id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
    name: 'Grace Hopper',
};

/** Ten members, so the eight-row cap and the group slots both bite. */
const MANY_MEMBERS: RosterMember[] = Array.from({ length: 10 }, (_, index) => ({
    id: `cccccccc-cccc-4ccc-8ccc-cccccccccc${String(index).padStart(2, '0')}`,
    name: `Person ${index}`,
    avatar: null,
    isBot: false,
    status: null,
    presence: 'active',
    isDnd: false,
}));

type SentMessage = { body: string; mentions: Mention[] };

let active: Array<{ app: App; container: HTMLElement }> = [];

function mountComposer(props: Record<string, unknown> = {}) {
    const sent: SentMessage[] = [];
    const container = document.createElement('div');
    document.body.appendChild(container);
    const composer = ref<{ insertMention: (member: PersonRef) => void } | null>(
        null,
    );

    const app = createApp(
        defineComponent({
            setup: () => () =>
                h(MessageComposer as Component, {
                    ref: composer,
                    channelName: 'general',
                    members: [ADA, GRACE],
                    teamSlug: 'acme',
                    channelSlug: 'general',
                    onSend: (body: string, mentions: Mention[]) =>
                        sent.push({ body, mentions }),
                    ...props,
                }),
        }),
    );
    // Interpolating stub: the group rows read their member count through a
    // `:count` placeholder, which a key-only stub would swallow.
    app.config.globalProperties.$t = (
        key: string,
        replacements: Record<string, string | number> = {},
    ) =>
        Object.entries(replacements).reduce(
            (text, [token, value]) => text.replace(`:${token}`, String(value)),
            key,
        );
    app.mount(container);
    active.push({ app, container });

    const textarea = container.querySelector<HTMLTextAreaElement>(
        '[data-test="message-composer-input"]',
    )!;

    return { composer, container, sent, textarea };
}

/** Set the field's value + caret and fire the composer's `input` handler. */
function type(textarea: HTMLTextAreaElement, value: string): Promise<void> {
    textarea.value = value;
    textarea.setSelectionRange(value.length, value.length);
    textarea.dispatchEvent(new Event('input', { bubbles: true }));

    return nextTick();
}

function press(textarea: HTMLTextAreaElement, key: string): Promise<void> {
    textarea.dispatchEvent(
        new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }),
    );

    return nextTick();
}

function options(container: HTMLElement): HTMLElement[] {
    return Array.from(
        container.querySelectorAll<HTMLElement>('[data-test="mention-option"]'),
    );
}

/**
 * A row's label, past the leading initials/icon badge every row opens with.
 */
function labelOf(row: HTMLElement): string {
    return row.querySelectorAll('span')[1].textContent?.trim() ?? '';
}

function send(container: HTMLElement): void {
    container
        .querySelector<HTMLButtonElement>(
            '[data-test="message-composer-send"]',
        )!
        .click();
}

afterEach(() => {
    active.forEach(({ app, container }) => {
        app.unmount();
        container.remove();
    });
    active = [];
});

describe('MessageComposer mention autocomplete', () => {
    it('opens the menu on @ and filters people by name', async () => {
        const { container, textarea } = mountComposer();

        await type(textarea, '@');
        expect(
            container.querySelector('[data-test="mention-menu"]'),
        ).not.toBeNull();

        await type(textarea, '@grace');
        const rows = options(container);
        expect(rows).toHaveLength(1);
        expect(rows[0].textContent).toContain('Grace Hopper');
    });

    it('closes the menu when the caret leaves a mention context', async () => {
        const { container, textarea } = mountComposer();

        await type(textarea, '@ada');
        expect(
            container.querySelector('[data-test="mention-menu"]'),
        ).not.toBeNull();

        await type(textarea, 'hello there');
        expect(
            container.querySelector('[data-test="mention-menu"]'),
        ).toBeNull();
    });

    it('reserves the tail slots for groups so a match is never crowded out', async () => {
        const { container, textarea } = mountComposer({
            members: MANY_MEMBERS,
        });

        await type(textarea, '@');
        const labels = options(container).map(labelOf);

        // Five people, then three of the four groups: a flat slice would have
        // spent all eight slots on names and hidden every group.
        expect(labels).toEqual([
            'Person 0',
            'Person 1',
            'Person 2',
            'Person 3',
            'Person 4',
            'design',
            'platform',
            'support',
        ]);
    });

    it('names how far a group mention reaches', async () => {
        const { container, textarea } = mountComposer();

        await type(textarea, '@p');
        const counts = Array.from(
            container.querySelectorAll<HTMLElement>(
                '[data-test="mention-option-group-count"]',
            ),
        ).map((node) => node.textContent?.trim());

        expect(counts).toEqual(['1 member', '7 members']);
    });

    it('completes a person into a mention token', async () => {
        const { container, textarea } = mountComposer();

        await type(textarea, 'hi @ad');
        options(container)[0].dispatchEvent(
            new MouseEvent('mousedown', { bubbles: true, cancelable: true }),
        );
        await nextTick();

        expect(textarea.value).toBe(`hi @[Ada Lovelace](${ADA.id}) `);
        expect(
            container.querySelector('[data-test="mention-menu"]'),
        ).toBeNull();
    });

    it('completes a group into a group token', async () => {
        const { textarea } = mountComposer();

        await type(textarea, '@design');
        await press(textarea, 'Enter');

        expect(textarea.value).toBe(`@[design](group:${groups[0].id}) `);
    });

    it('navigates with the arrow keys and closes on Escape', async () => {
        const { container, textarea } = mountComposer();

        await type(textarea, '@');
        await press(textarea, 'ArrowDown');
        expect(options(container)[1].getAttribute('aria-selected')).toBe(
            'true',
        );

        await press(textarea, 'ArrowUp');
        expect(options(container)[0].getAttribute('aria-selected')).toBe(
            'true',
        );

        await press(textarea, 'Escape');
        expect(
            container.querySelector('[data-test="mention-menu"]'),
        ).toBeNull();
    });

    it('completes the highlighted row on Tab', async () => {
        const { textarea } = mountComposer();

        await type(textarea, '@gr');
        await press(textarea, 'Tab');

        expect(textarea.value).toBe(`@[Grace Hopper](${GRACE.id}) `);
    });

    it('sends the distinct people named in the body', async () => {
        const { container, sent, textarea } = mountComposer();

        await type(
            textarea,
            `@[Ada Lovelace](${ADA.id}) @[Ada Lovelace](${ADA.id}) @[Grace Hopper](${GRACE.id}) ship it`,
        );
        send(container);
        await nextTick();

        expect(sent[0].mentions).toEqual([
            { id: ADA.id, name: ADA.name },
            { id: GRACE.id, name: GRACE.name },
        ]);
    });

    it('leaves a group token out of the optimistic mention list', async () => {
        const { container, sent, textarea } = mountComposer();

        await type(textarea, `@[design](group:${groups[0].id}) heads up`);
        send(container);
        await nextTick();

        // The fan-out is resolved server-side at post time, so the optimistic
        // row lists only the people it can already name.
        expect(sent[0].mentions).toEqual([]);
    });

    it('explains missing bots only in a channel that has one', async () => {
        const withoutBots = mountComposer();
        await type(withoutBots.textarea, '@');
        expect(
            withoutBots.container.querySelector(
                '[data-test="mention-bot-hint"]',
            ),
        ).toBeNull();

        const withBots = mountComposer({ hasBots: true });
        await type(withBots.textarea, '@');
        expect(
            withBots.container.querySelector('[data-test="mention-bot-hint"]'),
        ).not.toBeNull();
    });

    it('drops a mention in at the caret from outside the composer', async () => {
        const { composer, textarea } = mountComposer();

        await type(textarea, 'ping');
        composer.value!.insertMention(GRACE);
        await nextTick();

        expect(textarea.value).toBe(`ping @[Grace Hopper](${GRACE.id}) `);
    });
});
