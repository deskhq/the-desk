// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Covers the masthead's right side: the member facepile with its readout, the
 * add-people / pins / search controls, and every branch of the options menu —
 * which items each permission opens, and what each one emits.
 *
 * Written against the masthead as it stands so a split of it can be checked
 * against this suite. An extracted child that forgets to re-emit is exactly
 * what a pure move risks, and it is what these assertions are here to catch.
 */
vi.mock('@inertiajs/vue3', async () => {
    const { inertiaDouble } = await import('./ChannelMasthead.doubles');

    return inertiaDouble();
});

vi.mock('@lucide/vue', async () => {
    const { lucideDouble } = await import('./ChannelMasthead.stubs');

    return lucideDouble();
});

vi.mock('@/components/AvatarStack.vue', async () => {
    const { passthrough } = await import('./ChannelMasthead.stubs');

    return { default: passthrough('div') };
});

vi.mock('@/components/PresenceDot.vue', async () => {
    const { passthrough } = await import('./ChannelMasthead.stubs');

    return { default: passthrough('span') };
});

vi.mock('@/components/ui/avatar', async () => {
    const { avatarDouble } = await import('./ChannelMasthead.stubs');

    return avatarDouble();
});

vi.mock('@/components/ui/button', async () => {
    const { buttonDouble } = await import('./ChannelMasthead.stubs');

    return buttonDouble();
});

vi.mock('@/components/ui/dropdown-menu', async () => {
    const { dropdownMenuDouble } = await import('./ChannelMasthead.stubs');

    return dropdownMenuDouble();
});

vi.mock('@/components/ui/sidebar', async () => {
    const { sidebarDouble } = await import('./ChannelMasthead.doubles');

    return sidebarDouble();
});

vi.mock('@/components/ui/tooltip', async () => {
    const { tooltipDouble } = await import('./ChannelMasthead.stubs');

    return tooltipDouble();
});

vi.mock('@/composables/useIsMobile', async () => {
    const { isMobileDouble } = await import('./ChannelMasthead.doubles');

    return isMobileDouble();
});

vi.mock('@/composables/useNavPanel', async () => {
    const { navPanelDouble } = await import('./ChannelMasthead.doubles');

    return navPanelDouble();
});

vi.mock('@/composables/useDialog', async () => {
    const { dialogDouble } = await import('./ChannelMasthead.doubles');

    return dialogDouble();
});

import type { Emitted } from './ChannelMasthead.doubles';
import {
    channel,
    click,
    dock,
    find,
    member,
    mountMasthead,
    navigation,
    participant,
    resetDoubles,
    unmountAll,
} from './ChannelMasthead.doubles';
import ChannelMasthead from './ChannelMasthead.vue';

function mount(props: Record<string, unknown> = {}): {
    host: HTMLElement;
    emitted: Emitted;
} {
    return mountMasthead(ChannelMasthead, props);
}

/** Every permission open at once, so a case can close the one it is about. */
const allPermissions = {
    canManagePreferences: true,
    canArchive: true,
    canDelete: true,
    canLeave: true,
};

const levels = [
    { value: 'all', label: 'All messages' },
    { value: 'mentions', label: 'Mentions only' },
    { value: 'nothing', label: 'Nothing' },
];

beforeEach(() => {
    resetDoubles();
});

afterEach(() => {
    unmountAll();
});

describe('the masthead facepile', () => {
    it('shows three avatars and folds the rest into a +N chip', () => {
        const { host } = mount({
            members: [
                member({ id: 'a', name: 'Ada' }),
                member({ id: 'b', name: 'Bo' }),
                member({ id: 'c', name: 'Cy' }),
                member({ id: 'd', name: 'Di' }),
                member({ id: 'e', name: 'Ed' }),
            ],
        });

        const facepile = find(host, 'masthead-members') as HTMLElement;

        expect(facepile.querySelectorAll('[title]')).toHaveLength(3);
        expect(facepile.textContent).toContain('+2');
    });

    it('counts the active members against the roster, so away reads as present but idle', () => {
        const { host } = mount({
            members: [
                member({ id: 'a' }),
                member({ id: 'b' }),
                member({ id: 'c' }),
            ],
            presenceFor: (id: string) => (id === 'a' ? 'active' : 'away'),
        });

        expect(find(host, 'masthead-active-count')?.textContent).toContain(
            '1 of 3 active',
        );
    });

    it('squares a bot’s avatar and gives it no presence dot', () => {
        const { host } = mount({
            members: [member({ id: 'bot', name: 'Ledger', isBot: true })],
        });

        const facepile = find(host, 'masthead-members') as HTMLElement;

        expect(facepile.querySelector('.rounded-md')).not.toBeNull();
        expect(find(host, 'masthead-member-presence')).toBeNull();
    });

    it('leaves the facepile off a DM, where the roster is the conversation', () => {
        const { host } = mount({
            channel: channel({
                isDirect: true,
                dmUserId: 'peer',
                dmParticipants: [participant()],
            }),
            members: [member()],
        });

        expect(find(host, 'masthead-members')).toBeNull();
    });
});

describe('the masthead controls', () => {
    it('offers add-people only to someone who may grow the conversation', () => {
        expect(find(mount().host, 'masthead-add-people')).toBeNull();

        const { host, emitted } = mount({ canAddPeople: true });

        click(host, '[data-test="masthead-add-people"]');

        expect(emitted.map(([event]) => event)).toContain('addPeople');
    });

    it('keeps the add-people label off the row below the breakpoint', () => {
        navigation.isMobile.value = true;

        const { host } = mount({ canAddPeople: true });
        const button = find(host, 'masthead-add-people') as HTMLElement;

        expect(button.getAttribute('aria-label')).toBe('Add people');
        expect(button.textContent?.trim()).toBe('');
    });

    it('badges the pins button with its count, and only when there are pins', () => {
        expect(find(mount().host, 'masthead-pins-count')).toBeNull();

        const { host, emitted } = mount({ pinCount: 4 });

        expect(find(host, 'masthead-pins-count')?.textContent).toContain('4');
        expect(host.querySelector('.fill-brass')).not.toBeNull();

        click(host, '[data-test="masthead-pins"]');

        expect(emitted.map(([event]) => event)).toContain('openPins');
    });

    it('opens the dock’s search destination from the glyph, expanding a shut dock', () => {
        dock.open.value = false;

        const { host } = mount();

        click(host, '[data-test="masthead-search"]');

        expect(dock.setOpen).toHaveBeenCalledWith(true);
        expect(navigation.openDestination).toHaveBeenCalledWith('search');
        expect(navigation.openQuickSwitcher).not.toHaveBeenCalled();
    });

    it('sends the same glyph to the jump-to overlay below the breakpoint', () => {
        navigation.isMobile.value = true;

        const { host } = mount();

        click(host, '[data-test="masthead-search"]');

        expect(navigation.openQuickSwitcher).toHaveBeenCalled();
        expect(navigation.openDestination).not.toHaveBeenCalled();
    });
});

describe('the masthead options menu', () => {
    it('stays away entirely when the viewer may do none of it', () => {
        // A DM has no details to read, so with every permission closed there is
        // nothing left in the menu at all.
        const { host } = mount({
            channel: channel({
                isDirect: true,
                dmUserId: 'peer',
                dmParticipants: [participant()],
            }),
        });

        expect(find(host, 'channel-options')).toBeNull();
    });

    it('keeps the menu on a channel for its details alone', () => {
        const { host, emitted } = mount();

        const details = find(host, 'channel-details') as HTMLElement;

        expect(details.textContent).toContain('Channel details');

        details.click();

        expect(emitted.map(([event]) => event)).toContain('openDetails');
    });

    it('stars and unstars a channel without closing the menu', () => {
        const { host, emitted } = mount({
            ...allPermissions,
            notificationLevels: levels,
        });

        const star = find(host, 'star-channel') as HTMLElement;

        expect(star.getAttribute('aria-pressed')).toBe('false');
        expect(star.textContent).toContain('Star channel');

        star.click();

        expect(emitted.map(([event]) => event)).toContain('toggleStar');
    });

    it('reads back as unstarring once the channel is starred', () => {
        const { host } = mount({ ...allPermissions, starred: true });

        const star = find(host, 'star-channel') as HTMLElement;

        expect(star.getAttribute('aria-pressed')).toBe('true');
        expect(star.textContent).toContain('Unstar channel');
    });

    it('never offers to star a DM, which the sidebar never files', () => {
        const { host } = mount({
            ...allPermissions,
            channel: channel({
                isDirect: true,
                dmUserId: 'peer',
                dmParticipants: [participant()],
            }),
        });

        expect(find(host, 'channel-options')).not.toBeNull();
        expect(find(host, 'star-channel')).toBeNull();
    });

    it('lists every notification level and reports the pick', () => {
        const { host, emitted } = mount({
            ...allPermissions,
            notificationLevels: levels,
            notificationLevel: 'all',
        });

        expect(
            levels.map(
                ({ value }) =>
                    find(host, `notification-level-${value}`)?.textContent,
            ),
        ).toEqual(levels.map(({ label }) => label));

        click(host, '[data-test="notification-level-mentions"]');

        expect(emitted).toContainEqual(['notificationLevelChange', 'mentions']);
    });

    it('toggles the mute checkbox to the state it is not in', () => {
        const { host, emitted } = mount({ ...allPermissions, muted: true });

        const mute = find(host, 'mute-channel') as HTMLElement;

        expect(mute.getAttribute('aria-checked')).toBe('true');
        expect(mute.textContent).toContain('Mute channel');

        mute.click();

        expect(emitted).toContainEqual(['muteChange', false]);
    });

    it('offers archiving only to someone who may archive', () => {
        expect(
            find(mount({ canManagePreferences: true }).host, 'archive-channel'),
        ).toBeNull();

        const { host, emitted } = mount({ canArchive: true });

        click(host, '[data-test="archive-channel"]');

        expect(emitted.map(([event]) => event)).toContain('archive');
    });

    it('offers deleting only to someone who may delete, separately from archiving', () => {
        expect(
            find(mount({ canArchive: true }).host, 'delete-channel'),
        ).toBeNull();

        const { host, emitted } = mount({ canDelete: true });

        click(host, '[data-test="delete-channel"]');

        expect(emitted.map(([event]) => event)).toContain('delete');
    });

    it('names leaving after the kind of conversation it is', () => {
        const { host, emitted } = mount({ canLeave: true });

        expect(find(host, 'leave-channel')?.textContent).toContain(
            'Leave channel',
        );

        click(host, '[data-test="leave-channel"]');

        expect(emitted.map(([event]) => event)).toContain('leave');

        const dm = mount({
            canLeave: true,
            channel: channel({
                isDirect: true,
                dmUserId: 'peer',
                dmParticipants: [participant()],
            }),
        });

        expect(find(dm.host, 'leave-channel')?.textContent).toContain(
            'Leave conversation',
        );
    });
});
