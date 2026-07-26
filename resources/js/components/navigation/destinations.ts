import { AlarmClock, Hash, MessagesSquare, Search } from '@lucide/vue';
import type { Component } from 'vue';
import type { NavDestination } from '@/composables/useNavPanel';

export interface NavDestinationGlyph {
    /** The panel this glyph opens. */
    destination: NavDestination;
    /** The English source string; translated where it renders. */
    label: string;
    /** The lucide glyph the rail and the tab bar both draw. */
    icon: Component;
}

/**
 * The four glyph destinations, in the order the rail stacks them and the tab
 * bar lays them out. The fifth destination — "You" — is drawn as the viewer's
 * own avatar rather than a glyph, so each surface renders it in its own
 * markup (bottom of the rail, last tab of the bar) instead of iterating it.
 */
export const NAV_DESTINATION_GLYPHS: readonly NavDestinationGlyph[] = [
    { destination: 'channels', label: 'Channels', icon: Hash },
    { destination: 'threads', label: 'Threads', icon: MessagesSquare },
    { destination: 'reminders', label: 'Reminders', icon: AlarmClock },
    { destination: 'search', label: 'Search', icon: Search },
];

/**
 * The English source string naming each destination's panel, for the heading a
 * destination view carries and for the "You" entry the glyph list leaves out.
 */
export const NAV_DESTINATION_LABELS: Record<NavDestination, string> = {
    channels: 'Channels',
    threads: 'Threads',
    reminders: 'Reminders',
    search: 'Search',
    you: 'You',
};
