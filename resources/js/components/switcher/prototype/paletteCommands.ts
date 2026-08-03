/**
 * PROTOTYPE — throwaway, branch `prototype/palette-list-shape`.
 *
 * The launch catalogue settled by #1210, expressed as data so the three
 * list-shape variants have a real list to rank rather than a list of one.
 * This is NOT the shipping contract from #1209: `run` is stubbed wherever a
 * real call would mutate or navigate away from the palette, because the
 * question here is what the list looks like, not whether a verb works.
 */
import {
    AlarmClock,
    ArrowDown,
    ArrowUp,
    Bell,
    BellOff,
    Hash,
    Keyboard,
    MessageSquarePlus,
    Monitor,
    Moon,
    Search,
    Smile,
    Sun,
    UserPlus,
} from '@lucide/vue';
import { ref } from 'vue';
import type { Component } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { updateTheme } from '@/composables/useAppearance';
import type { Appearance } from '@/composables/useAppearance';
import { useDialog } from '@/composables/useDialog';
import { urlForDestination } from '@/composables/useNavPanel';
import { pinUrl } from '@/lib/pinUrl';

export type PrototypeCommand = {
    id: string;
    /** The English source string; #1210 owns these words. */
    title: string;
    icon: Component;
    /** Key tokens inherited from a claimed `ShortcutId`, when it claims one. */
    keys?: string[];
    isAvailable?: () => boolean;
    run: () => void;
};

/** Whatever a variant last ran, so the prototype bar can name it back. */
export const lastRun = ref('');

function note(title: string): void {
    lastRun.value = title;
    console.info(`[palette prototype] ran: ${title}`);
}

/** Stubbed: the real verb navigates or mutates, which would end the session. */
function stub(title: string): () => void {
    return () => note(`${title} (stubbed)`);
}

function real(title: string, effect: () => void): () => void {
    return () => {
        note(title);
        effect();
    };
}

function props(): Record<string, unknown> {
    return usePage().props as unknown as Record<string, unknown>;
}

/**
 * The theme rows without `useAppearance()`, whose `onMounted` call is exactly
 * the module-scope collision #1210 recorded — no point re-staging it here.
 */
function setTheme(value: Appearance): void {
    localStorage.setItem('appearance', value);
    updateTheme(value);
}

/**
 * The fifteen visible rows of #1210's catalogue, in the order the catalogue
 * lists them. The hidden `command-palette` entry is omitted: it never renders,
 * so it has nothing to say about list shape.
 */
export const PROTOTYPE_COMMANDS: PrototypeCommand[] = [
    {
        id: 'previous-channel',
        title: 'Go to previous channel',
        icon: ArrowUp,
        keys: ['⌥', '↑'],
        run: stub('Go to previous channel'),
    },
    {
        id: 'next-channel',
        title: 'Go to next channel',
        icon: ArrowDown,
        keys: ['⌥', '↓'],
        run: stub('Go to next channel'),
    },
    {
        id: 'focus-notifications',
        title: 'Focus notifications',
        icon: Bell,
        keys: ['F6'],
        run: stub('Focus notifications'),
    },
    {
        id: 'show-shortcuts',
        title: 'Show keyboard shortcuts',
        icon: Keyboard,
        keys: ['?'],
        run: real('Show keyboard shortcuts', () =>
            useDialog('shortcuts').open(),
        ),
    },
    {
        id: 'new-message',
        title: 'New message',
        icon: MessageSquarePlus,
        isAvailable: () => Boolean(props().currentTeam),
        run: real('New message', () => useDialog('newMessage').open()),
    },
    {
        id: 'browse-channels',
        title: 'Browse channels',
        icon: Hash,
        isAvailable: () => Boolean(props().currentTeam),
        run: stub('Browse channels'),
    },
    {
        id: 'reminders',
        title: 'Reminders',
        icon: AlarmClock,
        run: real('Reminders', () =>
            pinUrl(urlForDestination(usePage().url, 'reminders')),
        ),
    },
    {
        id: 'search',
        title: 'Search',
        icon: Search,
        run: real('Search', () =>
            pinUrl(urlForDestination(usePage().url, 'search')),
        ),
    },
    {
        id: 'set-status',
        title: 'Set a status',
        icon: Smile,
        run: real('Set a status', () => useDialog('status').open()),
    },
    {
        id: 'pause-notifications',
        title: 'Pause notifications',
        icon: BellOff,
        run: real('Pause notifications', () => useDialog('dnd').open()),
    },
    {
        id: 'resume-notifications',
        title: 'Resume notifications',
        icon: Bell,
        // The real predicate asks whether DND is active now. Forced on here so
        // the row is visible next to "Pause notifications" and the pair can be
        // judged as list content.
        run: stub('Resume notifications'),
    },
    {
        id: 'invite-people',
        title: 'Invite people',
        icon: UserPlus,
        isAvailable: () => props().canInviteToCurrentTeam === true,
        run: real('Invite people', () => useDialog('invite').open()),
    },
    {
        id: 'theme-light',
        title: 'Use light theme',
        icon: Sun,
        run: real('Use light theme', () => setTheme('light')),
    },
    {
        id: 'theme-dark',
        title: 'Use dark theme',
        icon: Moon,
        run: real('Use dark theme', () => setTheme('dark')),
    },
    {
        id: 'theme-system',
        title: 'Match system theme',
        icon: Monitor,
        run: real('Match system theme', () => setTheme('system')),
    },
];

/** The rows the viewer is allowed to see at all. */
export function availableCommands(): PrototypeCommand[] {
    return PROTOTYPE_COMMANDS.filter(
        (command) => command.isAvailable?.() ?? true,
    );
}
