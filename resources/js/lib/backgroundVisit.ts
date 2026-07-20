/**
 * The visit options every fire-and-forget Inertia request must carry.
 *
 * A background request is one the user never asked for: a debounced write, a
 * timer poll, a websocket-driven refresh, an auto-detection on first load. It
 * fires at a moment nobody chose, and as a *synchronous* visit it interrupts
 * whatever visit is in flight — which is how a mark-read POST cancelled the
 * thread panel's partial GET and stranded the panel empty (#581), and how any
 * of the rest can silently kill a real user navigation (#586).
 *
 * - `async` takes the request out of the synchronous queue, so it neither
 *   interrupts an in-flight visit nor is interrupted by one. It also drops the
 *   progress bar, which a request the user never triggered has no business
 *   showing.
 * - `preserveUrl` keeps the redirect-follow from rewriting the address bar.
 *   Without it a background response can drop query state the user is looking at
 *   (`?thread=`), or land the URL back on the page they just navigated away from
 *   when it resolves after their visit.
 *
 * Spread it into the options of any such request:
 * `{ ...backgroundVisit, preserveScroll: true, only: ['channels'] }`.
 *
 * Requests the user *did* ask for stay synchronous: form submits, navigations,
 * and reloads whose whole point is to rewrite the URL (the search page's
 * filter reload) — there the interrupt semantics are the correct ones.
 */
export const backgroundVisit = {
    async: true,
    preserveUrl: true,
} as const;
