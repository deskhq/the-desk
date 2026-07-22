import { router, usePage } from '@inertiajs/vue3';
import { echo } from '@laravel/echo-vue';
import { computed, onBeforeUnmount, onMounted, ref, toValue, watch } from 'vue';
import type { MaybeRefOrGetter } from 'vue';
import { backgroundVisit } from '@/lib/backgroundVisit';
import type { RenderedPresence } from '@/lib/presence';

/**
 * How long to coalesce a burst of profile updates before reloading, so many
 * teammates changing avatars at once trigger a single authoritative refetch.
 */
const PROFILE_RELOAD_DEBOUNCE_MS = 400;

/**
 * A team member as carried on the `team.{id}` presence roster. Mirrors the
 * `user_info` returned by the channel authorization callback.
 */
export type PresenceMember = {
    id: string;
    name: string;
};

/**
 * A teammate flipping between active and away mid-session, as carried by the
 * server-broadcast `UserPresenceChanged` event.
 */
export type PresenceFlip = {
    id: string;
    state: App.Enums.PresenceState;
};

/**
 * Tracks which members of a team are reachable via the `team.{id}` Reverb
 * presence channel, and how reachable each of them is.
 *
 * Two sources are composed, because neither answers alone. The roster (`here` /
 * `joining` / `leaving`) is authoritative for *connected at all* and is instant,
 * but its `user_info` is frozen at join, so a mid-session flip cannot live
 * there. The active/away refinement therefore arrives separately: seeded from
 * the presence the server put in the initial props (a client that has just
 * loaded has no event history) and then patched by `UserPresenceChanged`.
 *
 * Callers get a single `presenceFor` rather than the two sources, so none of the
 * dot surfaces can compose them differently.
 *
 * The subscription follows the given team id: switching teams leaves the old
 * presence channel and joins the new one, and the channel is left on unmount.
 */
export function useTeamPresence(teamId: MaybeRefOrGetter<string | undefined>) {
    const page = usePage();

    // Ids of the members currently present, driving the online dots.
    const onlineIds = ref<Set<string>>(new Set());

    // Live active/away flips received since this client loaded, by user id.
    // Authoritative over the props seed, which can only be as fresh as the last
    // Inertia visit.
    const flips = ref<Map<string, App.Enums.PresenceState>>(new Map());

    // A pending debounced profile-update reload, if any.
    let profileReloadTimer: ReturnType<typeof setTimeout> | undefined;

    /**
     * The presence the server resolved for each teammate when these props were
     * built — the cold-start answer for anyone who has not flipped since.
     */
    const seeded = computed(() => {
        const states = new Map<string, App.Enums.PresenceState>();

        for (const member of page.props.teamMembers ?? []) {
            if (member.presence) {
                states.set(member.id, member.presence);
            }
        }

        return states;
    });

    function channelName(id: string): string {
        return `team.${id}`;
    }

    /**
     * How the given member should render: offline unless they hold a connection,
     * and then whatever they last reported.
     *
     * Anyone connected but unaccounted for reads as active — a teammate on older
     * JS, one whose registry entry was evicted, an unreachable cache. Each of
     * those degrades to exactly the behaviour that predates away rather than to a
     * wrong "away".
     */
    function presenceFor(userId: string): RenderedPresence {
        if (!onlineIds.value.has(userId)) {
            return 'offline';
        }

        return flips.value.get(userId) ?? seeded.value.get(userId) ?? 'active';
    }

    // A teammate changed their profile (avatar, custom status). Reload the
    // current page's props so every surface re-reads them, preserving scroll and
    // local state so the refresh is invisible. A teammate's timing is not the
    // viewer's, and this one reloads *every* prop, so interrupting a visit with
    // it is especially costly; see {@see backgroundVisit}. Presence deliberately
    // does not come through here — it flips far too often to pay this price.
    function scheduleProfileReload(): void {
        clearTimeout(profileReloadTimer);
        profileReloadTimer = setTimeout(() => {
            router.reload({ ...backgroundVisit });
        }, PROFILE_RELOAD_DEBOUNCE_MS);
    }

    function replace(ids: Iterable<string>): void {
        onlineIds.value = new Set(ids);
    }

    function forgetFlip(id: string): void {
        if (!flips.value.has(id)) {
            return;
        }

        const next = new Map(flips.value);
        next.delete(id);
        flips.value = next;
    }

    function join(id: string): void {
        echo()
            .join(channelName(id))
            .here((members: PresenceMember[]) => {
                replace(members.map((member) => member.id));
            })
            .joining((member: PresenceMember) => {
                // Their last flip predates this connection and may describe a
                // session that has since ended, so the props seed is the fresher
                // claim until they report again.
                forgetFlip(member.id);
                onlineIds.value = new Set(onlineIds.value).add(member.id);
            })
            .leaving((member: PresenceMember) => {
                forgetFlip(member.id);

                const next = new Set(onlineIds.value);
                next.delete(member.id);
                onlineIds.value = next;
            })
            .listen('UserProfileUpdated', scheduleProfileReload)
            .listen('UserPresenceChanged', (flip: PresenceFlip) => {
                flips.value = new Map(flips.value).set(flip.id, flip.state);
            });
    }

    function leave(id: string): void {
        echo().leave(channelName(id));
    }

    // Echo opens a websocket, so it must only be touched in the browser: joining
    // during setup would run on the SSR pass and try to connect there.
    onMounted(() => {
        const id = toValue(teamId);

        if (id) {
            join(id);
        }
    });

    // Follow the active team once mounted: re-subscribe when it changes,
    // clearing the roster so a stale team's presence never bleeds into the next.
    watch(
        () => toValue(teamId),
        (newId, oldId) => {
            if (oldId) {
                leave(oldId);
            }

            replace([]);
            flips.value = new Map();

            if (newId) {
                join(newId);
            }
        },
    );

    onBeforeUnmount(() => {
        clearTimeout(profileReloadTimer);

        const id = toValue(teamId);

        if (id) {
            leave(id);
        }
    });

    return { onlineIds, presenceFor };
}
