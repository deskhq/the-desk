// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Covers what the palette lists before a message search comes back: the filter
 * field on each side of the breakpoint, the commands group, and the ranked
 * channel and people groups — the order the groups take, their highlighting, and
 * where picking one sends the viewer.
 *
 * Written against the palette as it stands so a split of it can be checked
 * against this suite: every selector, every string and every fallback is pinned
 * here, and a pure move may change none of them.
 */
vi.mock('@inertiajs/vue3', async () => {
    const { inertiaDouble } = await import('./CommandPalette.doubles');

    return inertiaDouble();
});

vi.mock('@lucide/vue', async () => {
    const { lucideDouble } = await import('./CommandPalette.stubs');

    return lucideDouble();
});

vi.mock('reka-ui', async () => {
    const { rekaDouble } = await import('./CommandPalette.stubs');

    return rekaDouble();
});

vi.mock(
    '@/actions/App/Http/Controllers/Channels/ChannelController',
    async () => {
        const { channelActionDouble } =
            await import('./CommandPalette.doubles');

        return channelActionDouble();
    },
);

vi.mock(
    '@/actions/App/Http/Controllers/Channels/SearchController',
    async () => {
        const { searchActionDouble } = await import('./CommandPalette.doubles');

        return searchActionDouble();
    },
);

vi.mock('@/components/PresenceDot.vue', async () => {
    const { presenceDotDouble } = await import('./CommandPalette.stubs');

    return presenceDotDouble();
});

vi.mock('@/components/SafeHtml.vue', async () => {
    const { safeHtmlDouble } = await import('./CommandPalette.stubs');

    return safeHtmlDouble();
});

vi.mock('@/components/ui/button', async () => {
    const { buttonDouble } = await import('./CommandPalette.stubs');

    return buttonDouble();
});

vi.mock('@/components/ui/command', async () => {
    const { commandDouble } = await import('./CommandPalette.stubs');

    return commandDouble();
});

vi.mock('@/components/ui/dialog', async () => {
    const { dialogDouble } = await import('./CommandPalette.stubs');

    return dialogDouble();
});

vi.mock('@/components/ui/sidebar', async () => {
    const { sidebarDouble } = await import('./CommandPalette.doubles');

    return sidebarDouble();
});

vi.mock('@/composables/useTeamPresence', async () => {
    const { teamPresenceDouble } = await import('./CommandPalette.doubles');

    return teamPresenceDouble();
});

vi.mock('@/composables/useIsMobile', async () => {
    const { isMobileDouble } = await import('./CommandPalette.doubles');

    return isMobileDouble();
});

vi.mock('@/composables/useMessageSearch', async () => {
    const { messageSearchDouble } = await import('./CommandPalette.doubles');

    return messageSearchDouble();
});

vi.mock('@/composables/useOpenDirectMessage', async () => {
    const { openDirectMessageDouble } =
        await import('./CommandPalette.doubles');

    return openDirectMessageDouble();
});

vi.mock('@/lib/pinUrl', async () => {
    const { pinUrlDouble } = await import('./CommandPalette.doubles');

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
    pinUrl,
    presence,
    resetDoubles,
    router,
    type,
    unmountAll,
    viewer,
} from './CommandPalette.doubles';
import CommandPalette from './CommandPalette.vue';

function mount(props: Record<string, unknown> = {}) {
    return mountSwitcher(CommandPalette, props);
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

/** The groups the list renders, by heading, in the order they render. */
function groups(host: HTMLElement): string[] {
    return [...host.querySelectorAll('[data-heading]')].map(
        (group) => group.getAttribute('data-heading') ?? '',
    );
}

/**
 * What the Commands group offers, in the order it offers it — read off each
 * row's name rather than its text, which a claiming row ends with its keys.
 */
function commands(host: HTMLElement): string[] {
    return [
        ...(host.querySelector('[data-heading="Commands"]')?.children ?? []),
    ].map((row) => row.querySelector('span')?.textContent?.trim() ?? '');
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

describe('the commands group', () => {
    it('offers the whole catalogue while nothing is typed', () => {
        const { host } = mount();

        // The registry's own order, which no ranking has anything to say about
        // until something is typed.
        expect(commands(host)).toEqual([
            'Go to previous channel',
            'Go to next channel',
            'Focus notifications',
            'Show keyboard shortcuts',
            'New message',
            'Browse channels',
            'Reminders',
            'Search',
            'Set a status',
            // Only the away half of the presence pair: this viewer is active,
            // and the row naming the state they are already in is not offered.
            'Set yourself away',
            'Pause notifications',
            'Use light theme',
            'Use dark theme',
            'Match system theme',
        ]);
    });

    it('keeps the command that opens the palette out of the palette', () => {
        const { host } = mount();

        expect(find(host, 'quick-switcher-command-palette')).toBeNull();
    });

    it('narrows to the rows a query matches, best match first', async () => {
        viewer.dnd = { until: '2999-01-01T00:00:00Z' };

        const { host } = mount();

        await type(host, 'res');

        // Ahead of "Browse channels", which the query only reaches as a
        // scattered subsequence — this is what puts an undoable mutation under
        // Enter for someone who typed the start of its name.
        expect(commands(host)[0]).toBe('Resume notifications');
    });

    it('leaves a command its predicate refuses out of the DOM entirely', () => {
        viewer.dnd = null;

        const { host } = mount();

        expect(find(host, 'quick-switcher-resume-notifications')).toBeNull();
        expect(commands(host)).not.toContain('Resume notifications');
    });

    it('offers it once the predicate is satisfied', () => {
        viewer.dnd = { until: '2999-01-01T00:00:00Z' };

        const { host } = mount();

        expect(
            find(host, 'quick-switcher-resume-notifications'),
        ).not.toBeNull();
    });

    it('reads the permission a predicate is gated on off the page', () => {
        viewer.canInvite = true;

        const { host } = mount();

        expect(find(host, 'quick-switcher-invite-people')).not.toBeNull();
    });

    it('renders the keys of a row claiming a shortcut, in place of the hint', () => {
        const { host } = mount();

        const claiming = find(host, 'quick-switcher-show-shortcuts');

        expect(
            [...(claiming?.querySelectorAll('kbd') ?? [])].map(
                (key) => key.textContent,
            ),
        ).toEqual(['?']);
        expect(claiming?.textContent).not.toContain('↵');

        const plain = find(host, 'quick-switcher-reminders');

        expect(plain?.querySelector('kbd')).toBeNull();
        expect(plain?.textContent).toContain('↵');
    });

    it('runs the command a pick names, and dismisses on the way', () => {
        const { host, open } = mount();

        find(host, 'quick-switcher-reminders')?.click();

        expect(pinUrl).toHaveBeenCalledWith('/t/acme/c/general?nav=reminders');
        expect(open.value).toBe(false);
    });
});

describe('the order of the groups', () => {
    const channels = [
        channel({ id: 'c1', name: 'general', slug: 'general' }),
        channel({ id: 'c2', name: 'research', slug: 'research' }),
    ];

    it('keeps the destinations above the commands while nothing is typed', () => {
        const { host } = mount({ channels });

        expect(groups(host)).toEqual(['Channels', 'People', 'Commands']);
        // And the channel list is exactly the one that was there before.
        expect(names(host, 'quick-switcher-channel')).toEqual([
            '#general',
            '#research',
        ]);
    });

    it('lifts the commands over the destinations for a query naming a verb', async () => {
        const { host } = mount({ channels });

        await type(host, 'search');

        expect(groups(host)).toEqual(['Commands', 'Channels', 'Messages']);
    });

    it('leaves the channels on top for a query naming a channel', async () => {
        const { host } = mount({ channels });

        await type(host, 'gen');

        expect(groups(host)[0]).toBe('Channels');
    });

    it('falls back to the declared order on a tie', async () => {
        const { host } = mount({
            channels: [channel({ id: 'c1', name: 'search', slug: 'search' })],
            members: [person({ id: 'u2', name: 'Search' })],
        });

        await type(host, 'search');

        expect(groups(host)).toEqual([
            'Channels',
            'People',
            'Commands',
            'Messages',
        ]);
    });

    it('pins the messages last, whatever the groups above them score', async () => {
        const { host } = mount({ channels });

        await type(host, 'search');

        // They arrive on a debounce, so a group that jumped the queue a beat
        // after the typing stopped would move the Enter target under the hands.
        expect(groups(host).at(-1)).toBe('Messages');
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
        presence.presenceFor = () => 'away';

        const { host } = mount({ members, currentUserId: 'me' });

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
