import { usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import type { Ref } from 'vue';
import { pinUrl } from '@/lib/pinUrl';

/**
 * The dock's pinned destinations, in the order the rail and the tab bar list
 * them. "channels" is the conversation list the dock has always shown; the
 * other four swap the panel's contents while the rail and tab bar stay put.
 */
export const NAV_DESTINATIONS = [
    'channels',
    'threads',
    'reminders',
    'search',
    'you',
] as const;

export type NavDestination = (typeof NAV_DESTINATIONS)[number];

/** The destination the dock falls back to when none is pinned. */
export const DEFAULT_NAV_DESTINATION: NavDestination = 'channels';

/** The query parameter carrying the open destination. */
const NAV_PARAM = 'nav';

/**
 * Inertia's `page.url` is a root-relative path, which `URL` cannot parse on its
 * own. The base below only satisfies that constructor — it never reaches the
 * result, which is rebuilt from the path, query and hash alone.
 */
const URL_BASE = 'http://localhost';

/** Read the destination pinned on a URL, defaulting to the conversation list. */
export function destinationFromUrl(url: string): NavDestination {
    const pinned = new URL(url, URL_BASE).searchParams.get(NAV_PARAM);

    return (
        NAV_DESTINATIONS.find((destination) => destination === pinned) ??
        DEFAULT_NAV_DESTINATION
    );
}

/**
 * Pin a destination onto a URL, leaving every other query param in place (a
 * `?thread=` panel, a search's filters). The default destination drops the
 * param instead of spelling it out, so an ordinary channel URL stays clean.
 */
export function urlForDestination(
    url: string,
    destination: NavDestination,
): string {
    const target = new URL(url, URL_BASE);

    if (destination === DEFAULT_NAV_DESTINATION) {
        target.searchParams.delete(NAV_PARAM);
    } else {
        target.searchParams.set(NAV_PARAM, destination);
    }

    return `${target.pathname}${target.search}${target.hash}`;
}

export interface NavPanel {
    /** The destination the panel is currently rendering. */
    activeDestination: Ref<NavDestination>;
    /** Switch the panel to a destination and pin it on the current route. */
    openDestination: (destination: NavDestination) => void;
    /** Whether a destination is the open one, for `aria-current` and styling. */
    isActive: (destination: NavDestination) => boolean;
}

/**
 * Own which destination the dock's panel renders, mirroring
 * {@see useThreadPanel}: the open state lives client-side so a partial reload
 * that omits a destination's props cannot blank an open panel, and `?nav=` on
 * the current shell route makes it deep-linkable and reload-proof.
 *
 * Switching is a client-side visit ({@see pinUrl}) rather than a server round
 * trip — the destination itself carries no props yet, and a destination that
 * needs data reloads exactly the props it needs on top of this. The watcher
 * adopts the URL's destination on history traversal, and returns the panel to
 * the conversation list when a plain channel link navigates away.
 */
export function useNavPanel(): NavPanel {
    const page = usePage();
    const activeDestination = ref<NavDestination>(destinationFromUrl(page.url));

    function openDestination(destination: NavDestination): void {
        if (activeDestination.value === destination) {
            return;
        }

        activeDestination.value = destination;

        pinUrl(urlForDestination(page.url, destination));
    }

    function isActive(destination: NavDestination): boolean {
        return activeDestination.value === destination;
    }

    watch(
        () => page.url,
        (url) => {
            activeDestination.value = destinationFromUrl(url);
        },
    );

    return { activeDestination, openDestination, isActive };
}
