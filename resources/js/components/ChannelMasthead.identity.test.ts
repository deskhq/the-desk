// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Covers how the masthead names the conversation it heads: the three avatar
 * treatments (group stack, 1:1 counterpart, `#`), the notification cue beside
 * the title, and the sub-lines under it — the compact activity readout, the
 * group's participant count, the archived badge and the topic.
 *
 * Written against the masthead as it stands so a split of it can be checked
 * against this suite: every selector, every string and every fallback is
 * pinned here, and a pure move may change none of them.
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

vi.mock('@/composables/useTeamPresence', async () => {
    const { teamPresenceDouble } = await import('./ChannelMasthead.doubles');

    return teamPresenceDouble();
});

vi.mock('@/composables/useDialog', async () => {
    const { dialogDouble } = await import('./ChannelMasthead.doubles');

    return dialogDouble();
});

import {
    channel,
    find,
    member,
    mountMasthead,
    notificationIcon,
    participant,
    presence,
    resetDoubles,
    unmountAll,
    viewer,
} from './ChannelMasthead.doubles';
import ChannelMasthead from './ChannelMasthead.vue';

/** Mount the masthead over its defaults and hand back the host. */
function mount(props: Record<string, unknown> = {}): HTMLElement {
    return mountMasthead(ChannelMasthead, props).host;
}

/** The `src` of the avatar image inside the element with this selector. */
function avatarSrc(host: HTMLElement, dataTest: string): string | null {
    return (
        find(host, dataTest)?.querySelector('img')?.getAttribute('src') ?? null
    );
}

beforeEach(() => {
    resetDoubles();
});

afterEach(() => {
    unmountAll();
});

describe('the masthead title', () => {
    it('marks a standard channel with the hash and its name', () => {
        const host = mount({ title: 'general' });
        const heading = host.querySelector('h1') as HTMLElement;

        expect(heading.textContent).toContain('#');
        expect(heading.textContent).toContain('general');
        expect(find(host, 'masthead-dm-avatar')).toBeNull();
        expect(find(host, 'masthead-group-avatars')).toBeNull();
    });

    it('shows the counterpart’s avatar and presence on a 1:1', () => {
        presence.presenceFor = () => 'away';

        const host = mount({
            channel: channel({
                isDirect: true,
                name: 'Ada Lovelace',
                dmUserId: 'peer',
                dmParticipants: [participant({ avatar: '/ada.png' })],
            }),
            title: 'Ada Lovelace',
        });

        expect(avatarSrc(host, 'masthead-dm-avatar')).toBe('/ada.png');
        expect(
            find(host, 'masthead-dm-presence')?.getAttribute('presence'),
        ).toBe('away');
        expect(find(host, 'masthead-dm-avatar')?.textContent).toContain('Away');
    });

    it('falls back to the viewer’s own avatar and presence in a self-DM', () => {
        viewer.avatar = '/me.png';
        viewer.presence = 'away';
        presence.presenceFor = () => 'offline';

        const host = mount({
            channel: channel({
                isDirect: true,
                name: 'Me',
                dmUserId: null,
                dmParticipants: [],
            }),
            title: 'You',
        });

        expect(avatarSrc(host, 'masthead-dm-avatar')).toBe('/me.png');
        expect(
            find(host, 'masthead-dm-presence')?.getAttribute('presence'),
        ).toBe('away');
    });

    it('falls back to initials when the counterpart has no avatar', () => {
        const host = mount({
            channel: channel({
                isDirect: true,
                name: 'Ada Lovelace',
                dmUserId: 'peer',
                dmParticipants: [participant()],
            }),
            title: 'Ada Lovelace',
        });

        expect(avatarSrc(host, 'masthead-dm-avatar')).toBeNull();
        expect(find(host, 'masthead-dm-avatar')?.textContent).toContain('AL');
    });

    it('announces a paused counterpart rather than their presence', () => {
        presence.isDndFor = () => true;

        const host = mount({
            channel: channel({
                isDirect: true,
                name: 'Ada',
                dmUserId: 'peer',
                dmParticipants: [participant()],
            }),
            title: 'Ada',
        });

        expect(find(host, 'masthead-dm-avatar')?.textContent).toContain(
            'Notifications paused',
        );
        expect(find(host, 'masthead-dm-presence')?.getAttribute('is-dnd')).toBe(
            'true',
        );
    });

    it('stacks a group DM’s participants and counts the viewer into the subtitle', () => {
        const host = mount({
            channel: channel({
                isDirect: true,
                isGroupDirect: true,
                dmParticipants: [
                    participant({ id: 'a', name: 'Ada' }),
                    participant({ id: 'b', name: 'Bo' }),
                ],
            }),
            title: 'Ada, Bo',
        });

        expect(find(host, 'masthead-group-avatars')?.getAttribute('max')).toBe(
            '3',
        );
        expect(find(host, 'masthead-group-count')?.textContent).toContain(
            '3 participants, including you',
        );
    });

    it('flags a non-default notification state beside the title', () => {
        const host = mount({
            notificationStatus: {
                icon: notificationIcon,
                label: 'Muted',
                status: 'muted',
            },
        });

        const cue = find(host, 'notification-status') as HTMLElement;

        expect(cue.getAttribute('data-status')).toBe('muted');
        expect(cue.getAttribute('aria-label')).toBe('Muted');
    });

    it('shows no cue while notifications are at their default', () => {
        expect(find(mount(), 'notification-status')).toBeNull();
    });
});

describe('the masthead sub-lines', () => {
    it('stands the activity readout in for the facepile on a narrow viewport', () => {
        presence.presenceFor = (id: string) =>
            id === 'a' ? 'active' : 'offline';

        const host = mount({
            members: [
                member({ id: 'a' }),
                member({ id: 'b' }),
                member({ id: 'c' }),
            ],
        });

        expect(find(host, 'masthead-compact-activity')?.textContent).toContain(
            '1 of 3 active',
        );
    });

    it('drops the readout on a DM, and on a channel with no roster', () => {
        expect(find(mount(), 'masthead-compact-activity')).toBeNull();
        expect(
            find(
                mount({
                    channel: channel({ isDirect: true }),
                    members: [member()],
                }),
                'masthead-compact-activity',
            ),
        ).toBeNull();
    });

    it('badges an archived channel and prints its topic', () => {
        const host = mount({
            channel: channel({ isArchived: true, topic: 'Ledger talk' }),
        });

        expect(host.textContent).toContain('Archived');
        expect(host.textContent).toContain('Ledger talk');
    });

    it('leaves the meta row out when there is nothing in it', () => {
        expect(mount().textContent).not.toContain('Archived');
    });
});
