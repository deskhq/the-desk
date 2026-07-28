// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Covers the palette's message search: what it asks the suggest endpoint as the
 * query is edited, how it reads while it waits and when nothing matches, how a
 * match renders, and the two ways out of the group — into the message itself, or
 * over to the Search panel with the criteria in hand.
 *
 * Written against the palette as it stands so a split of it can be checked
 * against this suite: every selector, every string and every fallback is pinned
 * here, and a pure move may change none of them.
 */
vi.mock('@inertiajs/vue3', async () => {
    const { inertiaDouble } = await import('./QuickSwitcher.doubles');

    return inertiaDouble();
});

vi.mock('@lucide/vue', async () => {
    const { lucideDouble } = await import('./QuickSwitcher.stubs');

    return lucideDouble();
});

vi.mock('reka-ui', async () => {
    const { rekaDouble } = await import('./QuickSwitcher.stubs');

    return rekaDouble();
});

vi.mock(
    '@/actions/App/Http/Controllers/Channels/ChannelController',
    async () => {
        const { channelActionDouble } = await import('./QuickSwitcher.doubles');

        return channelActionDouble();
    },
);

vi.mock(
    '@/actions/App/Http/Controllers/Channels/SearchController',
    async () => {
        const { searchActionDouble } = await import('./QuickSwitcher.doubles');

        return searchActionDouble();
    },
);

vi.mock('@/components/PresenceDot.vue', async () => {
    const { presenceDotDouble } = await import('./QuickSwitcher.stubs');

    return presenceDotDouble();
});

vi.mock('@/components/SafeHtml.vue', async () => {
    const { safeHtmlDouble } = await import('./QuickSwitcher.stubs');

    return safeHtmlDouble();
});

vi.mock('@/components/ui/button', async () => {
    const { buttonDouble } = await import('./QuickSwitcher.stubs');

    return buttonDouble();
});

vi.mock('@/components/ui/command', async () => {
    const { commandDouble } = await import('./QuickSwitcher.stubs');

    return commandDouble();
});

vi.mock('@/components/ui/dialog', async () => {
    const { dialogDouble } = await import('./QuickSwitcher.stubs');

    return dialogDouble();
});

vi.mock('@/components/ui/sidebar', async () => {
    const { sidebarDouble } = await import('./QuickSwitcher.doubles');

    return sidebarDouble();
});

vi.mock('@/composables/useIsMobile', async () => {
    const { isMobileDouble } = await import('./QuickSwitcher.doubles');

    return isMobileDouble();
});

vi.mock('@/composables/useMessageSearch', async () => {
    const { messageSearchDouble } = await import('./QuickSwitcher.doubles');

    return messageSearchDouble();
});

vi.mock('@/composables/useOpenDirectMessage', async () => {
    const { openDirectMessageDouble } = await import('./QuickSwitcher.doubles');

    return openDirectMessageDouble();
});

vi.mock('@/lib/pinUrl', async () => {
    const { pinUrlDouble } = await import('./QuickSwitcher.doubles');

    return pinUrlDouble();
});

import {
    dock,
    find,
    findAll,
    isMobile,
    messageSearch,
    mountSwitcher,
    person,
    pinUrl,
    resetDoubles,
    router,
    searchResult,
    type,
    unmountAll,
    viewer,
} from './QuickSwitcher.doubles';
import QuickSwitcher from './QuickSwitcher.vue';

function mount(props: Record<string, unknown> = {}) {
    return mountSwitcher(QuickSwitcher, props);
}

/** The group's own text, with the rows it holds left in place. */
function messagesGroup(host: HTMLElement): string {
    return (
        host
            .querySelector('[data-heading="Messages"]')
            ?.textContent?.replace(/\s+/g, ' ')
            .trim() ?? ''
    );
}

beforeEach(() => {
    resetDoubles();
});

afterEach(() => {
    unmountAll();
});

describe('what the palette asks for', () => {
    it('sends the typed text as the query, on the suggest endpoint', async () => {
        const { host } = mount();

        await type(host, 'quokka');

        expect(messageSearch.search).toHaveBeenCalledWith(
            JSON.stringify({ q: 'quokka' }),
        );
        expect(messageSearch.buildUrl?.(JSON.stringify({ q: 'quokka' }))).toBe(
            '/t/acme/search/suggest?{"q":"quokka"}',
        );
    });

    it('resolves the tokens the page understands into the same filters', async () => {
        const { host } = mount({
            members: [person({ id: 'u2', name: 'Bob Member' })],
        });

        await type(host, 'from:bob quokka');

        expect(messageSearch.search).toHaveBeenCalledWith(
            JSON.stringify({ q: 'quokka', from: 'u2' }),
        );
    });

    it('clears rather than asking for a query with no text left in it', async () => {
        const { host } = mount({
            members: [person({ id: 'u2', name: 'Bob Member' })],
        });

        await type(host, 'from:bob');

        expect(messageSearch.search).not.toHaveBeenCalled();
        expect(messageSearch.reset).toHaveBeenCalled();
    });
});

describe('how the group reads', () => {
    it('stays away entirely until there is text to search for', async () => {
        const { host } = mount();

        expect(messagesGroup(host)).toBe('');

        await type(host, 'quokka');

        expect(messagesGroup(host)).toContain('No messages match');
    });

    it('says it is searching while the first answer is outstanding', async () => {
        const { host } = mount();

        messageSearch.isSearching.value = true;
        await type(host, 'quokka');

        expect(messagesGroup(host)).toContain('Searching…');
        expect(find(host, 'quick-switcher-no-messages')).toBeNull();
    });

    it('names the query back when nothing matched it', async () => {
        const { host } = mount();

        await type(host, 'quokka');

        expect(find(host, 'quick-switcher-no-messages')?.textContent).toContain(
            'No messages match “quokka”.',
        );
    });

    it('reads a match as its author, its channel, its time and its body', async () => {
        viewer.timezone = 'UTC';

        const { host } = mount();

        messageSearch.results.value = [searchResult()];
        await type(host, 'quokka');

        const row = find(host, 'quick-switcher-message');

        expect(row?.textContent).toContain('Grace Hopper');
        expect(row?.textContent).toContain('#general');
        expect(row?.textContent).toContain('Jan 2, 3:04 PM');
        expect(
            row?.querySelector('[data-html]')?.getAttribute('data-html'),
        ).toBe('the quokka danced at dawn');
    });

    it('stamps the time in the time zone the viewer reads in', async () => {
        viewer.timezone = 'America/New_York';

        const { host } = mount();

        messageSearch.results.value = [searchResult()];
        await type(host, 'quokka');

        expect(find(host, 'quick-switcher-message')?.textContent).toContain(
            'Jan 2, 10:04 AM',
        );
    });

    it('drops the hash for a direct message, which has no channel name to mark', async () => {
        const { host } = mount();

        messageSearch.results.value = [
            searchResult({
                channelName: 'Grace Hopper',
                isDirectMessage: true,
            }),
        ];
        await type(host, 'quokka');

        expect(find(host, 'quick-switcher-message')?.textContent).not.toContain(
            '#',
        );
    });
});

describe('the ways out of the group', () => {
    it('opens the channel a match sits in, pinned to the message', async () => {
        const { host, open } = mount();

        messageSearch.results.value = [searchResult()];
        await type(host, 'quokka');
        find(host, 'quick-switcher-message')?.click();

        expect(router.visit).toHaveBeenCalledWith(
            '/t/acme/c/general?message=m1',
        );
        expect(open.value).toBe(false);
    });

    it('hands the criteria to the Search destination on the current route', async () => {
        const { host, open } = mount();

        await type(host, 'quokka');
        find(host, 'quick-switcher-see-all')?.click();

        expect(pinUrl).toHaveBeenCalledWith(
            '/t/acme/c/general?nav=search&q=quokka',
        );
        expect(open.value).toBe(false);
    });

    it('names the query in the hand-off row', async () => {
        const { host } = mount();

        await type(host, 'quokka');

        expect(find(host, 'quick-switcher-see-all')?.textContent).toContain(
            'See all results for “quokka”',
        );
    });

    it('opens the drawer the panel lives in below the breakpoint', async () => {
        isMobile.value = true;

        const { host } = mount();

        await type(host, 'quokka');
        find(host, 'quick-switcher-see-all')?.click();

        expect(dock.setOpenMobile).toHaveBeenCalledWith(true);
        expect(dock.setOpen).not.toHaveBeenCalled();
    });

    it('expands a collapsed dock from the breakpoint up', async () => {
        dock.open.value = false;

        const { host } = mount();

        await type(host, 'quokka');
        find(host, 'quick-switcher-see-all')?.click();

        expect(dock.setOpen).toHaveBeenCalledWith(true);
        expect(dock.setOpenMobile).not.toHaveBeenCalled();
    });

    it('leaves an already-open dock alone', async () => {
        const { host } = mount();

        await type(host, 'quokka');
        find(host, 'quick-switcher-see-all')?.click();

        expect(dock.setOpen).not.toHaveBeenCalled();
        expect(dock.setOpenMobile).not.toHaveBeenCalled();
    });

    it('offers the hand-off even where a match is already listed', async () => {
        const { host } = mount();

        messageSearch.results.value = [searchResult()];
        await type(host, 'quokka');

        expect(findAll(host, 'quick-switcher-message')).toHaveLength(1);
        expect(find(host, 'quick-switcher-see-all')).not.toBeNull();
    });
});
