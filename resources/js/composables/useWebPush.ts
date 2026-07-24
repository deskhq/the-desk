import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { toast } from 'vue-sonner';
import { useTranslations } from '@/composables/useTranslations';
import {
    currentSubscription,
    disablePush,
    enablePush,
    pushSupported,
} from '@/lib/push';

export interface WebPush {
    /** Whether the toggle should be offered on this instance and device at all. */
    available: ComputedRef<boolean>;
    /** Whether this device is subscribed right now. */
    subscribed: Ref<boolean>;
    /** The user blocked notifications for this site; only they can undo it. */
    blocked: Ref<boolean>;
    /** A subscribe/unsubscribe round-trip is in flight. */
    busy: Ref<boolean>;
    /** Subscribe or unsubscribe this device. */
    toggle: (next: boolean) => Promise<void>;
}

/**
 * Own the per-device web push opt-in.
 *
 * Deliberately per device, not per account: a push subscription belongs to one
 * browser, so enabling it on a laptop must not start pushing to a phone that
 * was never asked. That makes the browser — not a shared Inertia prop — the
 * source of truth for whether the toggle reads as on, which is why the state is
 * read back from `pushManager` on mount rather than seeded from the server.
 *
 * Permission is requested only on an explicit toggle. Nothing prompts on load
 * or on login: an unprompted permission dialog is the fastest way to a
 * permanent block, and a block can only be lifted from the browser's own site
 * settings.
 */
export function useWebPush(): WebPush {
    const page = usePage();
    const { t } = useTranslations();

    const supported = ref(false);
    const subscribed = ref(false);
    const blocked = ref(false);
    const busy = ref(false);

    // The instance has to have a VAPID keypair, and the browser has to be able
    // to subscribe at all — on iOS that means an installed home-screen app
    // (#845 adds the in-app prompt that makes installing discoverable).
    const available = computed(
        () => page.props.webPush.enabled && supported.value,
    );

    onMounted(async () => {
        supported.value = pushSupported();

        if (!supported.value) {
            return;
        }

        blocked.value = Notification.permission === 'denied';
        subscribed.value = (await currentSubscription()) !== null;
    });

    async function toggle(next: boolean): Promise<void> {
        const publicKey = page.props.webPush.publicKey;

        if (busy.value || publicKey === null) {
            return;
        }

        busy.value = true;

        try {
            if (next) {
                // A declined prompt is an answer, not a failure: the switch
                // falls back to off and the blocked note explains the rest.
                subscribed.value = await enablePush(publicKey);
                blocked.value = Notification.permission === 'denied';

                return;
            }

            await disablePush();
            subscribed.value = false;
        } catch {
            // Leave the switch reflecting what the browser actually holds, so a
            // half-completed opt-in doesn't read as on.
            subscribed.value = (await currentSubscription()) !== null;
            toast.error(
                t('Could not update push notifications on this device.'),
            );
        } finally {
            busy.value = false;
        }
    }

    return { available, subscribed, blocked, busy, toggle };
}
