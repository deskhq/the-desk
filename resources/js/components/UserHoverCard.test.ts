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
        setup:
            (_, { slots }) =>
            () =>
                h('a', slots.default?.()),
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
});
