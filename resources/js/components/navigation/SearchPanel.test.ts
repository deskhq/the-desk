// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h, nextTick, reactive } from 'vue';
import type { MessageSearchResult } from '@/types';

const { get } = vi.hoisted(() => ({ get: vi.fn() }));

const page = reactive<{ url: string; props: Record<string, unknown> }>({
    url: '/t/acme/c/general?nav=search',
    props: {},
});

vi.mock('@inertiajs/vue3', () => ({
    router: { get },
    usePage: () => page,
    Link: defineComponent({
        props: { href: { type: String, default: '' } },
        setup:
            (props, { slots }) =>
            () =>
                h('a', { href: props.href }, slots.default?.()),
    }),
}));

vi.mock('@/components/SafeHtml.vue', () => ({
    default: defineComponent({
        props: { html: { type: String, default: '' } },
        setup: (props) => () => h('span', props.html),
    }),
}));

/**
 * The facet row is presentational (its chips and pickers are audited in the
 * browser suite); here it stands in as a probe that reports the labels it was
 * handed and can fire each of its events back.
 */
vi.mock('@/components/navigation/SearchFacetBar.vue', () => ({
    default: defineComponent({
        props: {
            authorName: { type: String, default: null },
            channelName: { type: String, default: null },
            dateLabel: { type: String, default: null },
        },
        emits: ['author', 'channel', 'range', 'clearAll'],
        setup:
            (props, { emit }) =>
            () =>
                h('div', { 'data-test': 'facet-bar-stub' }, [
                    h('span', { 'data-test': 'chip-author' }, props.authorName),
                    h(
                        'span',
                        { 'data-test': 'chip-channel' },
                        props.channelName,
                    ),
                    h('span', { 'data-test': 'chip-date' }, props.dateLabel),
                    h('button', {
                        'data-test': 'drop-author',
                        onClick: () => emit('author', null),
                    }),
                    h('button', {
                        'data-test': 'clear-all',
                        onClick: () => emit('clearAll'),
                    }),
                ]),
    }),
}));

import SearchPanel from './SearchPanel.vue';

function result(
    overrides: Partial<MessageSearchResult> = {},
): MessageSearchResult {
    return {
        message: {
            id: 'm-1',
            clientUuid: 'uuid-1',
            body: 'the brief is ready',
            type: 'standard',
            user: { id: 'u-1', name: 'Carol Danvers' },
            createdAt: '2026-07-20T14:02:00.000Z',
            editedAt: null,
            isDeleted: false,
            mentions: [],
            linkPreviews: [],
            reactions: [],
            attachments: [],
            poll: null,
            pin: null,
            replyTo: null,
            forwardedFrom: null,
            threadRootId: null,
            sentToChannel: false,
            threadReplyCount: 0,
            threadLastReplyAt: null,
            threadParticipants: [],
            threadFollowed: false,
            threadUnread: false,
            threadUnreadReplyCount: 0,
        },
        channelName: 'general',
        channelSlug: 'general',
        isDirectMessage: false,
        snippet: 'the <mark>brief</mark> is ready',
        teamId: 't-1',
        teamName: 'Acme',
        teamSlug: 'acme',
        ...overrides,
    } as MessageSearchResult;
}

/** The criteria the server echoes alongside a set of matches. */
function echo(
    overrides: Record<string, unknown> = {},
): Record<string, unknown> {
    return {
        q: '',
        from: null,
        in: null,
        after: null,
        before: null,
        scope: 'team',
        ...overrides,
    };
}

let app: App | null = null;
let root: HTMLDivElement;

function mount(): void {
    root = document.createElement('div');
    document.body.append(root);
    app = createApp(SearchPanel);
    // Stand in for the global helper, interpolating like the real one so the
    // copy that names a filter back can be asserted on.
    app.config.globalProperties.$t = (
        key: string,
        replacements: Record<string, string | number> = {},
    ): string =>
        Object.entries(replacements).reduce(
            (out, [token, value]) => out.replaceAll(`:${token}`, String(value)),
            key,
        );
    app.mount(root);
}

function find(selector: string): HTMLElement | null {
    return root.querySelector<HTMLElement>(`[data-test="${selector}"]`);
}

/** The URL the panel's last request asked for. */
function requestedUrl(): string {
    return get.mock.calls.at(-1)?.[0] as string;
}

beforeEach(() => {
    get.mockReset();
    page.url = '/t/acme/c/general?nav=search';
    page.props = {
        auth: { user: { id: 'u-9', name: 'Demo', timezone: 'UTC' } },
        currentTeam: { id: 't-1', name: 'Acme', slug: 'acme' },
        teams: [{ id: 't-1', name: 'Acme', slug: 'acme' }],
        channels: [
            {
                id: 'c-1',
                name: 'general',
                slug: 'general',
                visibility: 'public',
            },
        ],
        teamMembers: [{ id: 'u-1', name: 'Carol Danvers' }],
    };
});

afterEach(() => {
    app?.unmount();
    app = null;
    root.remove();
});

describe('SearchPanel', () => {
    it('pulls the matches when it opens without them', () => {
        page.url = '/t/acme/c/general?nav=search&q=brief';

        mount();

        expect(get).toHaveBeenCalledTimes(1);
        expect(requestedUrl()).toContain('q=brief');
        expect(requestedUrl()).toContain('nav=search');
    });

    // A shared link arrives with matches already computed for it; running the
    // same full-text query again on mount would be pure waste.
    it('trusts matches that already answer the url it opened on', () => {
        page.url = '/t/acme/c/general?nav=search&q=brief';
        page.props.searchCriteria = echo({ q: 'brief' });
        page.props.searchResults = [result()];

        mount();

        expect(get).not.toHaveBeenCalled();
        expect(find('search-result-count')?.textContent).toContain('1 result');
    });

    // Reopening the panel with matches left over from an earlier, different
    // search must not show them as the answer to the url now being asked.
    it('re-runs when the loaded matches answer a different search', () => {
        page.url = '/t/acme/c/general?nav=search&q=quokka';
        page.props.searchCriteria = echo({ q: 'brief' });
        page.props.searchResults = [result()];

        mount();

        expect(requestedUrl()).toContain('q=quokka');
    });

    it('adopts a query handed over while it is already open', async () => {
        page.props.searchCriteria = echo();
        page.props.searchResults = [];
        mount();
        expect(get).not.toHaveBeenCalled();

        // What the ⌘K palette does: write the criteria onto the current route
        // and let the panel notice.
        page.url = '/t/acme/c/general?nav=search&q=brief';
        await nextTick();

        expect(requestedUrl()).toContain('q=brief');
        expect(
            root.querySelector<HTMLInputElement>('[data-test="search-input"]')
                ?.value,
        ).toBe('brief');
    });

    it('asks only once for criteria the server keeps resolving differently', async () => {
        // A url the server would answer with something else entirely: without a
        // guard the echo would never match and the panel would loop.
        page.url = '/t/acme/c/general?nav=search&q=brief';
        page.props.searchCriteria = echo({ q: 'something else' });
        page.props.searchResults = [];

        mount();
        page.props.searchCriteria = echo({ q: 'something else again' });
        await nextTick();

        expect(get).toHaveBeenCalledTimes(1);
    });

    it('renders the chips from the url and drops one back out of it', async () => {
        page.url = '/t/acme/c/general?nav=search&q=brief&from=u-1&in=c-1';
        page.props.searchCriteria = echo({
            q: 'brief',
            from: 'u-1',
            in: 'c-1',
        });
        page.props.searchResults = [result()];

        mount();

        expect(find('chip-author')?.textContent).toBe('Carol Danvers');
        expect(find('chip-channel')?.textContent).toBe('general');

        find('drop-author')?.click();
        await nextTick();

        expect(requestedUrl()).not.toContain('from=');
        expect(requestedUrl()).toContain('in=c-1');
        expect(requestedUrl()).toContain('q=brief');
    });

    it('clears every facet but keeps the query', async () => {
        page.url =
            '/t/acme/c/general?nav=search&q=brief&from=u-1&after=2026-07-01';
        page.props.searchCriteria = echo({
            q: 'brief',
            from: 'u-1',
            after: '2026-07-01',
        });
        page.props.searchResults = [result()];

        mount();
        find('clear-all')?.click();
        await nextTick();

        expect(requestedUrl()).toBe('/t/acme/c/general?nav=search&q=brief');
    });

    it('drops a channel from another workspace when the scope narrows', async () => {
        page.url = '/t/acme/c/general?nav=search&q=brief&in=c-beta&scope=all';
        page.props.teams = [
            { id: 't-1', name: 'Acme', slug: 'acme' },
            { id: 't-2', name: 'Beta', slug: 'beta' },
        ];
        page.props.searchCriteria = echo({
            q: 'brief',
            in: 'c-beta',
            scope: 'all',
        });
        page.props.searchResults = [];
        page.props.searchWorkspaceChannels = [
            {
                id: 'c-beta',
                name: 'planning',
                slug: 'planning',
                visibility: 'public',
                teamName: 'Beta',
                teamSlug: 'beta',
            },
        ];

        mount();
        find('scope-team')?.click();
        await nextTick();

        expect(requestedUrl()).not.toContain('in=');
        expect(requestedUrl()).not.toContain('scope=');
    });

    it('hides the scope control from someone with a single workspace', () => {
        page.props.searchCriteria = echo();
        page.props.searchResults = [];

        mount();

        expect(find('scope-control')).toBeNull();
    });

    it('names the active filters back when nothing matches', () => {
        page.url = '/t/acme/c/general?nav=search&q=quokka&from=u-1';
        page.props.searchCriteria = echo({ q: 'quokka', from: 'u-1' });
        page.props.searchResults = [];

        mount();

        expect(find('search-empty')?.textContent).toContain('quokka');
        expect(find('search-empty')?.textContent).toContain('Carol Danvers');
        expect(find('search-clear-filters')).not.toBeNull();
    });

    it('invites a search until one is asked for', () => {
        page.props.searchCriteria = echo();
        page.props.searchResults = [];

        mount();

        expect(find('search-idle')).not.toBeNull();
        expect(find('search-empty')).toBeNull();
    });
});
