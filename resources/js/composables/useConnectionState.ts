import { useConnectionStatus } from '@laravel/echo-vue';
import type { ConnectionStatus } from '@laravel/echo-vue';
import { computed, onMounted, onScopeDispose, ref, watch } from 'vue';
import type { ComputedRef, Ref } from 'vue';

/**
 * The live Echo connection-status ref, or a static "connected" fallback under
 * SSR. Reading the status eagerly constructs the Echo/Pusher client, which has
 * no browser Pusher to construct on the server and throws; there we render as
 * connected and let the client take over the real status on hydration.
 */
function connectionStatusRef(): Ref<ConnectionStatus> {
    try {
        return useConnectionStatus();
    } catch {
        return ref<ConnectionStatus>('connected');
    }
}

/** How long the transient "Back online" confirmation pill stays up, in ms. */
export const BACK_ONLINE_MS = 3000;

/** What the masthead pill should show, or `null` when steadily connected. */
export type ConnectionPill = 'reconnecting' | 'back-online' | null;

/** Passed to a connect listener so it can tell a first connect from a recovery. */
export interface ConnectEvent {
    /** True when the socket had connected before this drop (a genuine recovery). */
    isReconnect: boolean;
}

export interface ConnectionState {
    /** Whether the realtime socket is currently connected. */
    isOnline: ComputedRef<boolean>;
    /** The pill to render: reconnecting, a transient back-online, or nothing. */
    pill: ComputedRef<ConnectionPill>;
    /**
     * Register a callback fired each time the socket becomes connected, told
     * whether it was a genuine reconnect. The channel page flushes the outbox on
     * every connect (so a queue rehydrated on load still sends) but only backfills
     * missed messages and confirms with a toast on a reconnect.
     */
    onConnected: (callback: (event: ConnectEvent) => void) => void;
}

/**
 * Observe the Reverb/Echo connection and derive the viewer-facing connection
 * state: a live `isOnline` flag, the masthead `pill`, and an `onConnected` hook
 * for connect side-effects. The raw five-value status is collapsed by
 * {@see connectionPill}; the stateful concerns here are the short "Back online"
 * window and distinguishing the first connect from a recovery.
 */
export function useConnectionState(): ConnectionState {
    const status = connectionStatusRef();
    const isOnline = computed(() => status.value === 'connected');

    // Under SSR the status is a static "connected", so the pill renders nothing.
    // The real client status is only known after Echo connects, which can't have
    // happened during the first (hydration) paint — surfacing "reconnecting" then
    // would render a pill the server didn't, a node-level hydration mismatch that
    // desyncs the whole tree. Hold the pill blank until mounted so first paint
    // matches SSR; the true status flows in on the next tick.
    const mounted = ref(false);
    onMounted(() => {
        mounted.value = true;
    });

    const showBackOnline = ref(false);
    const callbacks: Array<(event: ConnectEvent) => void> = [];
    let timer: ReturnType<typeof setTimeout> | null = null;
    // Whether the socket has ever connected in this page's life. Seeded from the
    // mount status so a page that boots already-connected still treats its first
    // drop-and-recover as a reconnect.
    let hasConnected = isOnline.value;

    function clearTimer(): void {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    }

    watch(isOnline, (online, wasOnline) => {
        if (online && wasOnline === false) {
            const isReconnect = hasConnected;
            hasConnected = true;
            callbacks.forEach((callback) => callback({ isReconnect }));

            // Confirm only a genuine recovery; a first connect needs no fanfare.
            if (isReconnect) {
                showBackOnline.value = true;
                clearTimer();
                timer = setTimeout(() => {
                    showBackOnline.value = false;
                    timer = null;
                }, BACK_ONLINE_MS);
            }
        } else if (!online) {
            // Fell over — drop any lingering confirmation immediately.
            showBackOnline.value = false;
            clearTimer();
        }
    });

    const pill = computed<ConnectionPill>(() => {
        if (!mounted.value) {
            return null;
        }

        if (!isOnline.value) {
            return 'reconnecting';
        }

        return showBackOnline.value ? 'back-online' : null;
    });

    function onConnected(callback: (event: ConnectEvent) => void): void {
        callbacks.push(callback);
    }

    onScopeDispose(clearTimer);

    return { isOnline, pill, onConnected };
}
