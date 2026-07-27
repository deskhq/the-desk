import { router, usePage } from '@inertiajs/vue3';

/**
 * How many times a swallowed write is re-issued before it is left alone.
 *
 * Losing twice over means a response landed inside two consecutive windows,
 * which the shell's handful of first-paint visits cannot sustain. Past that,
 * asking forever would trade a missing query param for a loop that keeps
 * stamping over whatever else is trying to land.
 */
const MAX_ATTEMPTS = 3;

/**
 * Write a URL onto the current route client-side, asking again if a concurrent
 * response swallowed it.
 *
 * Inertia stamps a token on the page before it awaits the component a visit
 * resolves to, and abandons the swap if another visit stamped over that token
 * meanwhile. A client-side visit resolves the component it is already on, so the
 * await buys it nothing — but it still opens a window, and a server response
 * landing inside that window drops the write with no error, no request and no
 * history entry: the URL simply never changes (#964). It bites in the first
 * moments after a login, where the shell's own fire-and-forget visits
 * ({@see backgroundVisit}) are in flight all at once, and it took a destination
 * opened from the page with it — the dock's rail was only spared because it
 * flips the panel itself rather than reading the switch back off the URL.
 *
 * Asking again is conditional on nothing else having moved the URL: once a real
 * navigation has landed it owns the address bar, and re-applying a write meant
 * for the route the viewer has left would drag them back onto it.
 */
export function pinUrl(url: string): void {
    const from = usePage().url;

    // A write that changes nothing has nothing observable to be swallowed, so
    // there is no second attempt to tell apart from a first one that worked.
    issue(url, from, url === from ? 1 : MAX_ATTEMPTS);
}

/** Issue one client-side visit, and judge whether it survived. */
function issue(url: string, from: string, attemptsLeft: number): void {
    router.replace({
        url,
        preserveState: true,
        preserveScroll: true,
        onFinish: () => {
            if (attemptsLeft <= 1) {
                return;
            }

            // Yield first. The response that beat us to the token is itself a
            // tick from applying, and asking again on top of it right now would
            // stamp over that one in turn — trading our dropped write for its.
            setTimeout(() => {
                // Any URL other than the one we started from means this write
                // has had its say: it landed, or the viewer went elsewhere.
                if (usePage().url !== from) {
                    return;
                }

                issue(url, from, attemptsLeft - 1);
            }, 0);
        },
    });
}
