import { router, usePage } from '@inertiajs/vue3';
import { echo } from '@laravel/echo-vue';
import { computed, onBeforeUnmount, onMounted, ref, toValue, watch } from 'vue';
import type { MaybeRefOrGetter } from 'vue';
import { backgroundVisit } from '@/lib/backgroundVisit';
import type { RenderedPresence } from '@/lib/presence';
import { TEAM_MEMBER_PROPS } from '@/lib/reloadProps';

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

/** How a team's presence reads, for any surface that draws a dot. */
export interface TeamPresence {
    /** How the given member should render on the roster. */
    presenceFor: (userId: string) => RenderedPresence;
    /** Whether the given member shows the crescent do-not-disturb badge. */
    isDndFor: (userId: string) => boolean;
}

// Ids of the members currently present, driving the online dots.
const onlineIds = ref<Set<string>>(new Set());

// Live active/away flips received since this client loaded, by user id.
// Authoritative over the props seed, which can only be as fresh as the last
// Inertia visit.
const flips = ref<Map<string, App.Enums.PresenceState>>(new Map());

// How many mounted subscribers are holding the channel open, and which team it
// is currently open on.
let subscriberCount = 0;
let joinedTeamId: string | undefined;

// A pending debounced profile-update reload, if any.
let profileReloadTimer: ReturnType<typeof setTimeout> | undefined;

// The shared roster prop, read through `usePage()` inside the computed rather
// than beside it: this module is evaluated at import time, before Inertia has a
// page to hand out.
const teamMembers = computed(() => usePage().props.teamMembers ?? []);

/**
 * The presence the server resolved for each teammate when these props were
 * built — the cold-start answer for anyone who has not flipped since.
 */
const seeded = computed(() => {
    const states = new Map<string, App.Enums.PresenceState>();

    for (const member of teamMembers.value) {
        if (member.presence) {
            states.set(member.id, member.presence);
        }
    }

    return states;
});

/**
 * The members currently flagged as in do-not-disturb, from the shared
 * `teamMembers` prop. No live patching layer like the presence flips:
 * every DND change broadcasts `UserProfileUpdated`, whose debounced reload
 * below refreshes this very prop.
 */
const dndIds = computed(() => {
    const ids = new Set<string>();

    for (const member of teamMembers.value) {
        if (member.isDnd) {
            ids.add(member.id);
        }
    }

    return ids;
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

/**
 * Whether the given member shows the crescent DND badge on their dot.
 */
function isDndFor(userId: string): boolean {
    return dndIds.value.has(userId);
}

// A teammate changed their profile (avatar, custom status, do-not-disturb).
// Reload the one prop that carries it, preserving scroll and local state so the
// refresh is invisible. A teammate's timing is not the viewer's, so interrupting
// a visit with it is especially costly; see {@see backgroundVisit}. Presence
// deliberately does not come through here — it flips far too often to pay this
// price, and rides `UserPresenceChanged` instead.
function scheduleProfileReload(): void {
    clearTimeout(profileReloadTimer);
    profileReloadTimer = setTimeout(() => {
        router.reload({ ...backgroundVisit, only: TEAM_MEMBER_PROPS });
    }, PROFILE_RELOAD_DEBOUNCE_MS);
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
            onlineIds.value = new Set(members.map((member) => member.id));
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

/**
 * Point the one subscription at the given team, clearing the roster so a stale
 * team's presence never bleeds into the next.
 *
 * Idempotent, which is what lets every subscriber ask for the team it wants
 * without any of them knowing whether another got there first: only a genuine
 * change re-subscribes. `undefined` is the teardown — no team, no channel.
 */
function syncTo(teamId: string | undefined): void {
    if (teamId === joinedTeamId) {
        return;
    }

    if (joinedTeamId) {
        echo().leave(channelName(joinedTeamId));
    }

    onlineIds.value = new Set();
    flips.value = new Map();
    joinedTeamId = teamId;

    if (teamId) {
        join(teamId);
    }
}

/**
 * Subscribe to a team's presence for as long as the calling component lives.
 *
 * The subscription is reference-counted rather than per-caller: the shell and
 * the open channel page both want the roster, both would otherwise join the
 * same cached Echo channel, and `echo().leave()` removes a channel for *every*
 * listener bound to it — so whichever of them unmounted first used to tear down
 * the channel the other was still rendering dots from. Here the first mount
 * joins, the rest are free, and only the last unmount leaves.
 *
 * The subscription follows the given team id: switching teams leaves the old
 * presence channel and joins the new one.
 *
 * Reading presence needs none of this — call {@see useTeamPresence}.
 */
export function useTeamPresenceSubscription(
    teamId: MaybeRefOrGetter<string | undefined>,
): void {
    // Echo opens a websocket, so the channel is only ever touched from a
    // mounted subscriber: joining during setup would run on the SSR pass and
    // try to connect there.
    let subscribed = false;

    onMounted(() => {
        subscribed = true;
        subscriberCount += 1;
        syncTo(toValue(teamId));
    });

    watch(
        () => toValue(teamId),
        (id) => {
            if (subscribed) {
                syncTo(id);
            }
        },
    );

    onBeforeUnmount(() => {
        if (!subscribed) {
            return;
        }

        subscribed = false;
        subscriberCount -= 1;

        if (subscriberCount > 0) {
            return;
        }

        clearTimeout(profileReloadTimer);
        profileReloadTimer = undefined;
        syncTo(undefined);
    });
}

/**
 * Read which members of the team are reachable, and how reachable each of them
 * is, at the point of use.
 *
 * Two sources are composed, because neither answers alone. The roster (`here` /
 * `joining` / `leaving`) is authoritative for *connected at all* and is instant,
 * but its `user_info` is frozen at join, so a mid-session flip cannot live
 * there. The active/away refinement therefore arrives separately: seeded from
 * the presence the server put in the initial props (a client that has just
 * loaded has no event history) and then patched by `UserPresenceChanged`.
 *
 * Callers get a single `presenceFor` rather than the two sources, so none of the
 * dot surfaces can compose them differently. The state is module-scoped, so a
 * dot four components deep reads it directly instead of being handed it — the
 * shape {@see useMessageActionsContext} gave the message-action scope.
 *
 * A surface that reads before anything subscribed sees an empty roster, which
 * renders everyone offline.
 */
export function useTeamPresence(): TeamPresence {
    return { presenceFor, isDndFor };
}
