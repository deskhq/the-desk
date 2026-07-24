import { computed, onMounted, ref } from 'vue';

/**
 * The event Chromium fires when the app meets the installability criteria. It
 * is not in `lib.dom`, so the two members we use are declared here.
 */
export type BeforeInstallPromptEvent = Event & {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
};

/** ISO timestamp of the ✕, "Not now", or a declined browser prompt. */
const DISMISSED_KEY = 'install.dismissedAt';

/** Set once the menu row has been carried by a menu the viewer opened. */
const ROW_SEEN_KEY = 'install.rowSeen';

/** How many browser sessions this device has spent in the app. */
const SESSIONS_KEY = 'install.sessions';

/** Marks the current browser session as already counted. */
const SESSION_COUNTED_KEY = 'install.sessionCounted';

/**
 * The card is an invitation, not a first-run interruption: it waits until the
 * viewer has come back at least once.
 */
const SESSIONS_BEFORE_CARD = 2;

const deferredPrompt = ref<BeforeInstallPromptEvent | null>(null);
const installed = ref(false);
const ios = ref(false);
const dismissed = ref(false);
const rowSeen = ref(false);
const sessions = ref(0);
const hydrated = ref(false);

/**
 * Storage is best-effort throughout: Safari's private mode and a disabled-cookie
 * profile both throw on access, and losing a preference must never cost the
 * viewer the surface itself.
 */
function read(storage: Storage, key: string): string | null {
    try {
        return storage.getItem(key);
    } catch {
        return null;
    }
}

function write(storage: Storage, key: string, value: string): void {
    try {
        storage.setItem(key, value);
    } catch {
        // Nothing to do: the in-memory refs still carry the change for this
        // session, it just won't outlive a reload.
    }
}

/**
 * Whether the page is running as an installed app rather than a browser tab —
 * the standard display-mode query, plus the non-standard flag iOS sets on a
 * home-screen launch.
 */
function isStandaloneLaunch(): boolean {
    return (
        window.matchMedia('(display-mode: standalone)').matches ||
        (navigator as Navigator & { standalone?: boolean }).standalone === true
    );
}

/**
 * Whether this is an iOS device, where no browser fires `beforeinstallprompt`
 * and installing means walking the user through the share sheet by hand.
 *
 * iPadOS 13+ reports a desktop Mac user agent, so a touch-capable "Mac" is the
 * only tell an iPad leaves.
 */
function isIos(): boolean {
    return (
        /iphone|ipad|ipod/i.test(navigator.userAgent) ||
        (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
    );
}

/**
 * The number of browser sessions this device has spent in the app, counting the
 * current one. A same-tab navigation re-runs the bundle, so the tally is guarded
 * by a session-scoped marker rather than incremented on every boot.
 */
function countSession(): number {
    const counted = read(window.sessionStorage, SESSION_COUNTED_KEY) !== null;
    const previous = Number(read(window.localStorage, SESSIONS_KEY) ?? '0');

    if (counted) {
        return previous;
    }

    const total = previous + 1;

    write(window.localStorage, SESSIONS_KEY, String(total));
    write(window.sessionStorage, SESSION_COUNTED_KEY, '1');

    return total;
}

/**
 * Installs the listeners that decide whether an install surface can be offered
 * at all, and reads back what this device has already been asked. Called once at
 * boot, before Vue mounts, because `beforeinstallprompt` fires early and only
 * once per page load.
 */
export function initializeAppInstall(): void {
    if (typeof window === 'undefined') {
        return;
    }

    installed.value = isStandaloneLaunch();
    ios.value = isIos();
    dismissed.value = read(window.localStorage, DISMISSED_KEY) !== null;
    rowSeen.value = read(window.localStorage, ROW_SEEN_KEY) !== null;
    sessions.value = countSession();

    window.addEventListener('beforeinstallprompt', (event) => {
        // Chromium's own mini-infobar is suppressed so our card is the single
        // invitation, shown on our terms rather than the browser's.
        event.preventDefault();
        deferredPrompt.value = event as BeforeInstallPromptEvent;
    });

    window.addEventListener('appinstalled', () => {
        installed.value = true;
        deferredPrompt.value = null;
    });
}

/**
 * Marks the invitation as answered: the ✕, "Not now", and a declined browser
 * prompt all land here, and the card never returns on this device.
 */
function recordDismissal(): void {
    dismissed.value = true;
    write(window.localStorage, DISMISSED_KEY, new Date().toISOString());
}

export function useAppInstall() {
    // The surfaces depend on browser state the server cannot see, so nothing
    // renders until the client has mounted — otherwise the first client render
    // disagrees with the server markup.
    onMounted(() => {
        hydrated.value = true;
    });

    /**
     * Whether the install action can only be explained, not performed: iOS
     * hides "Add to Home Screen" in the share sheet and exposes no API for it.
     */
    const isInstructional = computed(() => ios.value && !installed.value);

    /** The permanent home for install, in the user menu. */
    const showRow = computed(
        () =>
            hydrated.value &&
            !installed.value &&
            (deferredPrompt.value !== null || isInstructional.value),
    );

    /** The one-time invitation, above the sidebar's user footer. */
    const showCard = computed(
        () =>
            showRow.value &&
            !dismissed.value &&
            sessions.value >= SESSIONS_BEFORE_CARD,
    );

    /**
     * Hands the saved event back to the browser, whose own dialog opens on top
     * of ours. A declined choice is recorded so the invitation is not repeated.
     *
     * The event is spent either way — it can only be prompted once — so it is
     * released here and the row waits for the browser to offer another on a
     * later load.
     */
    async function promptInstall(): Promise<void> {
        const event = deferredPrompt.value;

        if (!event) {
            return;
        }

        deferredPrompt.value = null;

        await event.prompt();

        const { outcome } = await event.userChoice;

        if (outcome === 'dismissed') {
            recordDismissal();
        }
    }

    return {
        showRow,
        showCard,
        isInstructional,
        promptInstall,
        dismissCard: recordDismissal,
    };
}

/**
 * Whether this presentation of the user menu should carry the NEW badge beside
 * its install row. The answer is snapshotted when the menu mounts and the flag
 * written straight away, so the badge survives the open it appears in and never
 * returns after it.
 */
export function useInstallRowBadge() {
    const { showRow } = useAppInstall();
    const isNew = ref(false);

    onMounted(() => {
        if (!showRow.value || rowSeen.value) {
            return;
        }

        isNew.value = true;
        rowSeen.value = true;
        write(window.localStorage, ROW_SEEN_KEY, '1');
    });

    return isNew;
}
