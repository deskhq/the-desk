import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import { isDndActiveNow } from '@/lib/dnd';
import type { RenderedPresence } from '@/lib/presence';

export interface OwnPresence {
    /**
     * The viewer's own effective presence, read from the shared `auth.user`
     * prop so every chip that draws it (the user menu trigger, the rail's
     * avatar, the tab bar's) mirrors the same value and a flip lands in all of
     * them without a remount. Never "offline" — you are, by definition,
     * looking at it.
     */
    presence: ComputedRef<RenderedPresence>;
    /**
     * The viewer's own do-not-disturb state, evaluated locally from the full
     * configuration only their own prop carries — so the crescent appears the
     * moment a pause is set, without waiting for a broadcast round-trip.
     */
    isDnd: ComputedRef<boolean>;
}

/**
 * The viewer's own presence and do-not-disturb state, as the surfaces that draw
 * their own avatar need them. Shared so the user-menu chip and the dock's
 * destination avatars cannot drift apart.
 */
export function useOwnPresence(): OwnPresence {
    const page = usePage();

    return {
        presence: computed<RenderedPresence>(
            () => page.props.auth.user.presence ?? 'active',
        ),
        isDnd: computed(() =>
            isDndActiveNow(
                page.props.auth.user.dnd ?? null,
                page.props.auth.user.timezone ?? null,
            ),
        ),
    };
}
