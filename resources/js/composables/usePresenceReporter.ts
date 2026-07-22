import { usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';
import { parseXsrfToken } from '@/lib/uploadAttachment';
import { generateUuid } from '@/lib/uuid';
import { release, report } from '@/routes/presence';

/**
 * Where this tab's connection key lives.
 *
 * `sessionStorage` is per tab and survives navigations within it, which is
 * exactly the grain presence needs. The session would be too coarse — two tabs
 * share one, and since a tab only writes on a transition, a backgrounded tab's
 * "away" would land last and overrule the foreground tab being typed in.
 */
export const PRESENCE_CONNECTION_STORAGE_KEY = 'desk:presence-connection';

/**
 * How often a tab re-asserts its state.
 *
 * Only a backstop: `pagehide` releases the connection on every ordinary close,
 * so this exists purely so a crashed or killed tab eventually ages out of the
 * server-side index. That makes minutes the right order of magnitude, and it
 * stays comfortably inside the registry's own time-to-live.
 */
export const PRESENCE_HEARTBEAT_MS = 5 * 60 * 1000;

/**
 * How long after mount the tab announces itself.
 *
 * Deliberately off the page-load critical path: the register races nothing —
 * an unregistered tab already reads as active (the fail-open default), so the
 * only thing this delay postpones is a fresh active tab overriding an older
 * idle one by a few seconds. Firing it at mount would instead contend with the
 * Inertia visit and the websocket authorization requests of a loading page.
 */
export const PRESENCE_REGISTER_DELAY_MS = 3000;

/**
 * Ignore further activity for this long after handling some.
 *
 * A scroll or a burst of typing fires hundreds of events; all that is needed is
 * to know activity happened at all.
 */
const ACTIVITY_THROTTLE_MS = 1000;

/**
 * Input that counts as "still here". Deliberately real interaction rather than
 * `document.hasFocus()`, which is the house convention for gating a mark-read:
 * publishing "away" to thirty colleagues is a much stronger claim than deciding
 * whether to advance a read pointer, and clicking into a terminal beside the
 * window should not make it. A hidden tab needs no special case — it cannot
 * receive any of these, so it ages into away on its own.
 */
const ACTIVITY_EVENTS = [
    'pointerdown',
    'keydown',
    'scroll',
    'touchstart',
] as const;

/**
 * Reports this tab's idle state, so teammates see the viewer as away once every
 * device they are signed in on has gone quiet.
 *
 * Only transitions are sent, so an ordinary working session costs one request
 * when the tab opens and one on each idle↔active flip — the server decides
 * whether that even changes what teammates see.
 */
export function usePresenceReporter(): void {
    const page = usePage();

    let connectionId = '';
    let idle = false;
    let lastActivityAt = 0;
    let registerTimer: ReturnType<typeof setTimeout> | undefined;
    let idleTimer: ReturnType<typeof setTimeout> | undefined;
    let heartbeat: ReturnType<typeof setInterval> | undefined;

    function thresholdMs(): number {
        return (
            Math.max(page.props.presence?.awayAfterMinutes ?? 10, 1) * 60_000
        );
    }

    /**
     * A fire-and-forget POST.
     *
     * `keepalive` is what lets the release outlive the page that sent it. It is
     * `sendBeacon`'s mechanism, reached through `fetch` so the CSRF token can
     * ride a header — `sendBeacon` cannot set one, and exempting the endpoint
     * would let any page silently mark someone offline.
     */
    function post(url: string, body: Record<string, string>): void {
        const headers: Record<string, string> = {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        };

        const token = parseXsrfToken(document.cookie);

        if (token) {
            headers['X-XSRF-TOKEN'] = token;
        }

        void fetch(url, {
            method: 'POST',
            headers,
            body: JSON.stringify(body),
            keepalive: true,
        })?.catch?.(() => {
            // A dropped report self-heals: the next transition or heartbeat
            // re-states this tab, and an entry that never arrives reads as
            // active, which is the state that predates this feature.
        });
    }

    function reportState(state: App.Enums.PresenceState): void {
        post(report().url, { connection: connectionId, state });
    }

    function goIdle(): void {
        idle = true;
        reportState('away');
    }

    /**
     * Restart the countdown, and announce the return if this tab had lapsed.
     */
    function onActivity(): void {
        const now = Date.now();

        if (!idle && now - lastActivityAt < ACTIVITY_THROTTLE_MS) {
            return;
        }

        lastActivityAt = now;

        if (idle) {
            idle = false;
            reportState('active');
        }

        clearTimeout(idleTimer);
        idleTimer = setTimeout(goIdle, thresholdMs());
    }

    function onPageHide(): void {
        post(release().url, { connection: connectionId });
    }

    // Every browser API below (sessionStorage, listeners, timers) is absent on
    // the SSR pass, so none of this may run during setup.
    onMounted(() => {
        connectionId =
            sessionStorage.getItem(PRESENCE_CONNECTION_STORAGE_KEY) ??
            generateUuid();
        sessionStorage.setItem(PRESENCE_CONNECTION_STORAGE_KEY, connectionId);

        // Register before the first transition: a freshly opened tab that never
        // reports would leave an older, idle tab of the same account deciding.
        // Deferred so the request never contends with the page's own loading.
        registerTimer = setTimeout(
            () => reportState('active'),
            PRESENCE_REGISTER_DELAY_MS,
        );

        lastActivityAt = Date.now();
        idleTimer = setTimeout(goIdle, thresholdMs());
        heartbeat = setInterval(
            () => reportState(idle ? 'away' : 'active'),
            PRESENCE_HEARTBEAT_MS,
        );

        for (const event of ACTIVITY_EVENTS) {
            document.addEventListener(event, onActivity, { passive: true });
        }

        // Coming back to the window counts as being here; losing it does not
        // count as leaving — it simply stops refreshing the countdown, so the
        // full threshold still has to pass.
        window.addEventListener('focus', onActivity);
        window.addEventListener('pagehide', onPageHide);
    });

    onBeforeUnmount(() => {
        clearTimeout(registerTimer);
        clearTimeout(idleTimer);
        clearInterval(heartbeat);

        for (const event of ACTIVITY_EVENTS) {
            document.removeEventListener(event, onActivity);
        }

        window.removeEventListener('focus', onActivity);
        window.removeEventListener('pagehide', onPageHide);
    });
}
