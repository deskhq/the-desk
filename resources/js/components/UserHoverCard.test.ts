// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h } from 'vue';
import { translate } from '@/lib/i18n';

/**
 * Covers the card's provenance line: when a message displayed a name of its own
 * (a per-message identity override), the card names the account that actually
 * posted it, so a suspicious reader can always reach the real one.
 */
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: {} }),
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
                                : ((props.href as { url?: string })?.url ?? ''),
                    },
                    slots.default?.(),
                ),
    }),
}));

vi.mock('@/composables/useUserProfileCard', () => ({
    fetchUserProfile: () => Promise.resolve(null),
}));

vi.mock('@/composables/useOpenDirectMessage', () => ({
    useOpenDirectMessage: () => ({ openDirectMessage: () => {} }),
}));

/** The hover card only mounts its content when open, so force it open. */
vi.mock('@/components/ui/hover-card', () => ({
    HoverCard: defineComponent({
        name: 'HoverCardStub',
        setup:
            (_, { slots }) =>
            () =>
                h('div', slots.default?.()),
    }),
    HoverCardTrigger: defineComponent({
        name: 'HoverCardTriggerStub',
        setup:
            (_, { slots }) =>
            () =>
                h('div', slots.default?.()),
    }),
    HoverCardContent: defineComponent({
        name: 'HoverCardContentStub',
        setup:
            (_, { slots }) =>
            () =>
                h('div', slots.default?.()),
    }),
}));

const { default: UserHoverCard } = await import('./UserHoverCard.vue');

let active: Array<{ app: App; host: HTMLElement }> = [];

function mount(props: Record<string, unknown> = {}): HTMLElement {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp({
        render: () =>
            h(UserHoverCard, {
                teamSlug: 'acme',
                userId: 'bot',
                name: 'Deploy Bot',
                ...props,
            }),
    });
    app.config.globalProperties.$t = translate;
    app.mount(host);
    active.push({ app, host });

    return host;
}

afterEach(() => {
    for (const { app, host } of active) {
        app.unmount();
        host.remove();
    }

    active = [];
});

describe('the user hover card', () => {
    it('names the account behind a displayed identity that is not its own', () => {
        const host = mount({ viaName: 'Deploy Bot', name: 'Release Train' });

        expect(
            host.querySelector('[data-test="hover-card-via"]')?.textContent,
        ).toContain('via Deploy Bot');
    });

    it('adds no provenance line on an ordinary row', () => {
        const host = mount();

        expect(host.querySelector('[data-test="hover-card-via"]')).toBeNull();
    });

    it('names the webhook behind the row and links straight to it', () => {
        const host = mount({
            credential: {
                kind: 'incoming_webhook',
                id: 'hook-1',
                name: 'CI alerts',
                botId: null,
            },
        });

        expect(
            host.querySelector('[data-test="hover-card-credential"]')
                ?.textContent,
        ).toContain('Posted by the CI alerts webhook');

        const link = host.querySelector(
            '[data-test="hover-card-credential-link"]',
        );

        // The link lands on the integrations page with the offending hook named,
        // so revoking exactly that credential is the next click.
        expect(link?.getAttribute('href')).toContain('webhook=hook-1');

        // A link list would otherwise announce a bare "Review" per open card.
        expect(link?.querySelector('.sr-only')?.textContent).toContain(
            'Review the CI alerts webhook',
        );
    });

    it('names the API token behind the row and links to its bot’s rack', () => {
        const host = mount({
            credential: {
                kind: 'api_token',
                id: '7',
                name: 'CI deploys',
                botId: 'bot-1',
            },
        });

        const block = host.querySelector('[data-test="hover-card-credential"]');

        // Whole sentences per kind, not a shared frame with the noun slotted
        // in — a translator is never handed English word order to work around.
        expect(block?.textContent).toContain(
            'Posted by the CI deploys API token',
        );
        expect(block?.getAttribute('data-credential-kind')).toBe('api_token');

        const link = host.querySelector(
            '[data-test="hover-card-credential-link"]',
        );

        // A token is revoked from its bot's own page, not the team-level index,
        // which is why the credential carries the bot.
        expect(link?.getAttribute('href')).toContain('bots/bot-1');
        expect(link?.getAttribute('href')).toContain('token=7');
        expect(link?.querySelector('.sr-only')?.textContent).toContain(
            'Review the CI deploys API token',
        );
    });

    it('names no credential when the viewer was not told of one', () => {
        const host = mount();

        expect(
            host.querySelector('[data-test="hover-card-credential"]'),
        ).toBeNull();
    });
});
