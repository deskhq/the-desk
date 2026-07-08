import { computed, onBeforeUnmount, ref } from 'vue';

/**
 * A channel member currently composing a message, as carried by the `typing`
 * client whisper. Mirrors the shape of `currentUser` in the channel view.
 */
export type TypingUser = {
    id: string;
    name: string;
};

/**
 * Re-whisper at most this often while the local user keeps typing, so a burst
 * of keystrokes turns into an occasional heartbeat rather than one event each.
 */
const WHISPER_THROTTLE_MS = 2500;

/**
 * Forget a remote typist this long after their last whisper. Comfortably longer
 * than the throttle so an actively-typing peer never flickers out between beats.
 */
const TYPING_EXPIRY_MS = 4000;

/**
 * Tracks who is typing on a channel. Outbound keystrokes are throttled into
 * periodic `typing` whispers; inbound whispers populate a self-expiring roster
 * so the indicator clears on its own when a peer goes idle. All timers are torn
 * down on `reset()` (channel switch) and on unmount.
 */
export function useTypingIndicator(whisper: (user: TypingUser) => void) {
    // Roster of remote typists keyed by user id, mapped to their display name.
    const typists = ref<Map<string, string>>(new Map());

    // Pending expiry timers keyed by user id, kept outside reactivity.
    const timers = new Map<string, ReturnType<typeof setTimeout>>();

    // Timestamp of the last outbound whisper, for leading-edge throttling.
    let lastWhisperAt = 0;

    const typingNames = computed<string[]>(() => [...typists.value.values()]);

    /**
     * Signal that the local user is typing, whispering at most once per
     * throttle window.
     */
    function signalTyping(user: TypingUser): void {
        const now = Date.now();

        if (now - lastWhisperAt < WHISPER_THROTTLE_MS) {
            return;
        }

        lastWhisperAt = now;
        whisper(user);
    }

    /**
     * Record (or refresh) a remote typist, resetting their expiry countdown.
     */
    function receiveTyping(user: TypingUser): void {
        clearExpiry(user.id);

        const next = new Map(typists.value);
        next.set(user.id, user.name);
        typists.value = next;

        timers.set(
            user.id,
            setTimeout(() => forget(user.id), TYPING_EXPIRY_MS),
        );
    }

    /**
     * Drop a typist immediately, e.g. once their message lands.
     */
    function forget(id: string): void {
        clearExpiry(id);

        if (!typists.value.has(id)) {
            return;
        }

        const next = new Map(typists.value);
        next.delete(id);
        typists.value = next;
    }

    /**
     * Clear the whole roster and every timer, e.g. when switching channels.
     */
    function reset(): void {
        for (const timer of timers.values()) {
            clearTimeout(timer);
        }

        timers.clear();
        typists.value = new Map();
        lastWhisperAt = 0;
    }

    function clearExpiry(id: string): void {
        const timer = timers.get(id);

        if (timer) {
            clearTimeout(timer);
            timers.delete(id);
        }
    }

    onBeforeUnmount(reset);

    return { typingNames, signalTyping, receiveTyping, forget, reset };
}
