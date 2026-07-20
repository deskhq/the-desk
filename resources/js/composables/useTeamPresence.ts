import { router } from '@inertiajs/vue3';
import { echo } from '@laravel/echo-vue';
import { onBeforeUnmount, onMounted, ref, toValue, watch } from 'vue';
import type { MaybeRefOrGetter } from 'vue';
import { backgroundVisit } from '@/lib/backgroundVisit';

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
 * Tracks which members of a team are currently online via the `team.{id}`
 * Reverb presence channel. `here` seeds the roster on join, while `joining`
 * and `leaving` keep it live as members open and close the workspace.
 *
 * The subscription follows the given team id: switching teams leaves the old
 * presence channel and joins the new one, and the channel is left on unmount.
 */
export function useTeamPresence(teamId: MaybeRefOrGetter<string | undefined>) {
    // Ids of the members currently present, driving the online dots.
    const onlineIds = ref<Set<string>>(new Set());

    // A pending debounced profile-update reload, if any.
    let profileReloadTimer: ReturnType<typeof setTimeout> | undefined;

    function channelName(id: string): string {
        return `team.${id}`;
    }

    // A teammate changed their profile (today: their avatar). Reload the current
    // page's props so every avatar surface re-reads the new image, preserving
    // scroll and local state so the refresh is invisible. A teammate's timing is
    // not the viewer's, and this one reloads *every* prop, so interrupting a
    // visit with it is especially costly; see {@see backgroundVisit}.
    function scheduleProfileReload(): void {
        clearTimeout(profileReloadTimer);
        profileReloadTimer = setTimeout(() => {
            router.reload({ ...backgroundVisit });
        }, PROFILE_RELOAD_DEBOUNCE_MS);
    }

    function replace(ids: Iterable<string>): void {
        onlineIds.value = new Set(ids);
    }

    function join(id: string): void {
        echo()
            .join(channelName(id))
            .here((members: PresenceMember[]) => {
                replace(members.map((member) => member.id));
            })
            .joining((member: PresenceMember) => {
                onlineIds.value = new Set(onlineIds.value).add(member.id);
            })
            .leaving((member: PresenceMember) => {
                const next = new Set(onlineIds.value);
                next.delete(member.id);
                onlineIds.value = next;
            })
            .listen('UserProfileUpdated', scheduleProfileReload);
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

    return { onlineIds };
}
