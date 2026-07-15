import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

/**
 * The update-available indicator's shared state.
 *
 * The backend shares `update = { current, latest, updateAvailable, notesUrl }`
 * (or null) on every authenticated request. The dock strip is dismissed
 * client-side, keyed by the latest version, so it reappears automatically on the
 * next release without any server-side dismissal record.
 */
const DISMISS_PREFIX = 'dismissed-update:';

function dismissKey(version: string): string {
    return `${DISMISS_PREFIX}${version}`;
}

function isDismissed(version: string): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    // localStorage can throw (Safari private mode, storage disabled); a read
    // failure just means "not dismissed" and must never break the mount check.
    try {
        return window.localStorage.getItem(dismissKey(version)) !== null;
    } catch {
        return false;
    }
}

export function useUpdateStatus() {
    const page = usePage();

    const status = computed(() => page.props.update ?? null);

    // Dismissal lives in localStorage, which SSR can't see. Reading it during
    // setup would make the first client render disagree with the server markup
    // (hydration mismatch), so the strip stays hidden until mounted, then reads
    // the dismissal and fades in — matching the design's "appear on mount".
    const hydrated = ref(false);
    const dismissedLatest = ref<string | null>(null);

    onMounted(() => {
        const latest = status.value?.latest;

        if (latest && isDismissed(latest)) {
            dismissedLatest.value = latest;
        }

        hydrated.value = true;
    });

    const isBehind = computed(() => status.value?.updateAvailable ?? false);

    /** Whether the dock strip should render: mounted, behind, and not dismissed. */
    const showStrip = computed(
        () =>
            hydrated.value &&
            isBehind.value &&
            dismissedLatest.value !== status.value?.latest,
    );

    function dismiss(): void {
        const latest = status.value?.latest;

        if (!latest) {
            return;
        }

        if (typeof window !== 'undefined') {
            try {
                window.localStorage.setItem(dismissKey(latest), '1');
            } catch {
                // Storage full or unavailable: the strip still hides for this
                // session below; it just won't stay dismissed on reload.
            }
        }

        dismissedLatest.value = latest;
    }

    return { status, isBehind, showStrip, dismiss };
}
