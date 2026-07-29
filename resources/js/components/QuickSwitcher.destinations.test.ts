// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Covers what the palette lists before a message search comes back: the filter
 * field on each side of the breakpoint, the Reminders action, and the ranked
 * channel and people groups — their order, their highlighting, and where
 * picking one sends the viewer.
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
    channel,
    find,
    findAll,
    flush,
    isMobile,
    messageSearch,
    mountSwitcher,
    openDirectMessage,
    person,
    resetDoubles,
    router,
    type,
    unmountAll,
} from './QuickSwitcher.doubles';
import QuickSwitcher from './QuickSwitcher.vue';

function mount(props: Record<string, unknown> = {}) {
    return mountSwitcher(QuickSwitcher, props);
}

/**
 * What the rows carrying this selector read, in the order they render, with the
 * enter hint dropped — it is drawn on every desktop row and says nothing about
 * which one this is.
 */
function names(host: HTMLElement, dataTest: string): string[] {
    return findAll(host, dataTest).map((row) =>
        (row.textContent ?? '').replaceAll('↵', '').trim(),
    );
}

beforeEach(() => {
    resetDoubles();
});

afterEach(() => {
    unmountAll();
});

describe('the filter field', () => {
    it('offers a plain row from the breakpoint up, with no Cancel', () => {
        const { host } = mount();

        expect(find(host, 'quick-switcher-input')).not.toBeNull();
        expect(find(host, 'quick-switcher-cancel')).toBeNull();
    });

    it('offers a Cancel beside the field below the breakpoint', () => {
        isMobile.value = true;

        const { host, open } = mount();

        find(host, 'quick-switcher-cancel')?.click();

        expect(open.value).toBe(false);
    });

    it('clears the query and the message results on dismissal', async () => {
        const { host, open } = mount();

        await type(host, 'quokka');
        open.value = false;
        await flush();

        expect(
            (find(host, 'quick-switcher-input') as HTMLInputElement).value,
        ).toBe('');
        expect(messageSearch.reset).toHaveBeenCalled();
    });
});

describe('the actions group', () => {
    it('offers Reminders while nothing is typed', () => {
        const { host, emitted, open } = mount();

        find(host, 'quick-switcher-reminders')?.click();

        expect(emitted).toEqual([['openReminders', undefined]]);
        expect(open.value).toBe(false);
    });

    it('keeps offering it for a bare hash, which reads as no query', async () => {
        const { host } = mount();

        await type(host, '#');

        expect(find(host, 'quick-switcher-reminders')).not.toBeNull();
    });

    it('drops it as soon as the viewer types', async () => {
        const { host } = mount();

        await type(host, 'q');

        expect(find(host, 'quick-switcher-reminders')).toBeNull();
    });
});

describe('the channels group', () => {
    const channels = [
        channel({ id: 'c1', name: 'general', slug: 'general' }),
        channel({
            id: 'c2',
            name: 'design',
            slug: 'design',
            lastActivityAt: '2026-01-03T00:00:00Z',
        }),
        channel({
            id: 'c3',
            name: 'planning',
            slug: 'planning',
            lastActivityAt: '2026-01-01T00:00:00Z',
        }),
    ];

    it('ranks alphabetically from the breakpoint up', () => {
        const { host } = mount({ channels });

        expect(names(host, 'quick-switcher-channel')).toEqual([
            '#design',
            '#general',
            '#planning',
        ]);
    });

    it('ranks by activity below it, so an empty query reads as recents', () => {
        isMobile.value = true;

        const { host } = mount({ channels });

        expect(names(host, 'quick-switcher-channel')).toEqual([
            '#design',
            '#planning',
            '#general',
        ]);
    });

    it('brightens the matched run below the breakpoint', async () => {
        isMobile.value = true;

        const { host } = mount({ channels });

        await type(host, 'des');

        expect(find(host, 'quick-switcher-match')?.textContent).toBe('des');
    });

    it('leaves the name whole from the breakpoint up', async () => {
        const { host } = mount({ channels });

        await type(host, 'des');

        expect(find(host, 'quick-switcher-match')).toBeNull();
        expect(names(host, 'quick-switcher-channel')).toEqual(['#design']);
    });

    it('navigates into the channel a pick names', () => {
        const { host, open } = mount({ channels });

        find(host, 'quick-switcher-channel')?.click();

        expect(router.visit).toHaveBeenCalledWith('/t/acme/c/design');
        expect(open.value).toBe(false);
    });
});

describe('the people group', () => {
    const members = [
        person({ id: 'me', name: 'Ada Lovelace' }),
        person({ id: 'u2', name: 'Bob Member' }),
    ];

    it('names the viewer themselves "You", with their initials beside it', () => {
        const { host } = mount({ members, currentUserId: 'me' });

        expect(names(host, 'quick-switcher-person')).toEqual([
            'ALYou',
            'BMBob Member',
        ]);
    });

    it('reads out presence instead of the enter hint below the breakpoint', () => {
        isMobile.value = true;

        const { host } = mount({
            members,
            currentUserId: 'me',
            presenceFor: () => 'away',
        });

        expect(names(host, 'quick-switcher-person')[0]).toContain('Away');
    });

    it('brightens the matched run of a name below the breakpoint', async () => {
        isMobile.value = true;

        const { host } = mount({ members, currentUserId: 'me' });

        await type(host, 'bo');

        expect(names(host, 'quick-switcher-person')).toEqual([
            'BMBob MemberActive',
        ]);
        expect(find(host, 'quick-switcher-match')?.textContent).toBe('Bo');
    });

    it('opens the direct message a pick names', () => {
        const { host, open } = mount({ members, currentUserId: 'me' });

        findAll(host, 'quick-switcher-person')[1]?.click();

        expect(openDirectMessage).toHaveBeenCalledWith('u2');
        expect(open.value).toBe(false);
    });
});
