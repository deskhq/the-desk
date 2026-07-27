<script setup lang="ts">
import { Plus } from '@lucide/vue';
import { computed } from 'vue';
import CreateTeamModal from '@/components/CreateTeamModal.vue';
import { NAV_DESTINATION_GLYPHS } from '@/components/navigation/destinations';
import WorkspaceSheet from '@/components/navigation/WorkspaceSheet.vue';
import PresenceDot from '@/components/PresenceDot.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import UserMenuPopover from '@/components/UserMenuPopover.vue';
import { useInitials } from '@/composables/useInitials';
import type { NavDestination } from '@/composables/useNavPanel';
import { useTeamSwitch } from '@/composables/useTeamSwitch';
import type { RenderedPresence } from '@/lib/presence';
import type { Team, User } from '@/types';

/**
 * The dock's desktop rail: a 56px column of pinned destinations that stays put
 * while the panel beside it swaps contents. Workspace identity sits at the top,
 * the four glyph destinations under a divider, and the viewer's own avatar —
 * the "You" destination — at the bottom, in thumb-free reach of the pointer.
 *
 * The rail owns no state: the open destination arrives as a prop and every
 * activation leaves as `select`, so the layout stays the single seam for
 * `?nav=` bookkeeping.
 */
const props = defineProps<{
    /** The destination the panel is rendering, marked here with `aria-current`. */
    active: NavDestination;
    /** The viewer, whose avatar is the "You" destination. */
    user: User;
    /** Every workspace the viewer belongs to, one tile each; empty off a workspace. */
    teams: Team[];
    /** The viewer's own effective presence, for the avatar's corner dot. */
    presence: RenderedPresence;
    /** Whether the viewer is in do-not-disturb, drawn as the dot's crescent. */
    isDnd: boolean;
    /** Whether any followed thread is unread, dotting the threads glyph. */
    hasUnreadThreads: boolean;
}>();

const emit = defineEmits<{
    select: [destination: NavDestination];
    /** The workspace sheet's "invite people" row was chosen; the host owns the modal. */
    invite: [];
    /** The workspace sheet's "join a workspace" row was chosen. */
    join: [];
}>();

const { getInitials } = useInitials();
const { switchTeam } = useTeamSwitch();

const hasAvatar = computed(
    () => !!props.user.avatar && props.user.avatar !== '',
);

/**
 * The press an open destination wears. Kept here rather than inline so the
 * avatar at the foot of the rail reads the same as the glyphs above it.
 *
 * The open tile takes the *panel's* surface rather than the accent one: on the
 * sand theme `--sidebar-accent` and `--sidebar-rail` land within a shade of
 * each other, so an accent press is invisible in the light theme even though
 * it reads fine in the dark one.
 */
/**
 * Whether a workspace has anything waiting. The tile draws a plain dot either
 * way: the exact count belongs to the workspace sheet's rows, where there is
 * room to read it.
 */
function hasUnread(team: Team): boolean {
    return team.unreadCount > 0 || team.mentionCount > 0;
}

function glyphClass(destination: NavDestination): string {
    return props.active === destination
        ? 'bg-sidebar text-sidebar-foreground shadow-sm'
        : 'text-sidebar-foreground/70 hover:bg-sidebar/60 hover:text-sidebar-foreground';
}
</script>

<template>
    <nav
        data-test="navigation-rail"
        :aria-label="$t('Destinations')"
        class="flex w-14 shrink-0 flex-col items-center gap-1.5 border-sidebar-border bg-sidebar-rail py-3"
    >
        <!-- One tile per workspace, in their own scroller: a viewer in many
             workspaces must never push the destination glyphs below the fold.
             Tapping another workspace switches straight to it; tapping the open
             one opens the workspace sheet — the rail's anchor onto the surface
             the two headers also carry. -->
        <div
            v-if="teams.length > 0"
            class="flex min-h-0 w-full shrink flex-col items-center gap-2 overflow-y-auto overscroll-contain py-1"
        >
            <template v-for="team in teams" :key="team.id">
                <WorkspaceSheet
                    v-if="team.isCurrent"
                    side="right"
                    @invite="emit('invite')"
                    @join="emit('join')"
                >
                    <Button
                        variant="ghost"
                        size="icon"
                        data-test="rail-workspace-tile"
                        data-current="true"
                        :title="team.name"
                        class="size-8.5 shrink-0 rounded-[10px] bg-sidebar-primary text-[11px] font-semibold text-sidebar-primary-foreground ring-2 ring-brass ring-offset-2 ring-offset-sidebar-rail hover:bg-sidebar-primary"
                    >
                        <span aria-hidden="true">{{
                            getInitials(team.name)
                        }}</span>
                        <span class="sr-only">{{
                            $t('Workspace: :name', { name: team.name })
                        }}</span>
                    </Button>
                </WorkspaceSheet>
                <Button
                    v-else
                    variant="ghost"
                    size="icon"
                    data-test="rail-workspace-tile"
                    :title="team.name"
                    class="relative size-8.5 shrink-0 rounded-[10px] bg-sidebar text-[11px] font-semibold text-sidebar-foreground/80 transition-colors hover:text-sidebar-foreground"
                    @click="switchTeam(team)"
                >
                    <span aria-hidden="true">{{ getInitials(team.name) }}</span>
                    <span class="sr-only">{{
                        $t('Switch to :name', { name: team.name })
                    }}</span>
                    <template v-if="hasUnread(team)">
                        <span
                            data-test="rail-workspace-unread-dot"
                            aria-hidden="true"
                            class="absolute -top-0.5 -right-0.5 size-2 rounded-full bg-brass ring-2 ring-sidebar-rail"
                        />
                        <!-- The dot is decorative; the state is named for
                             assistive tech in the tile's own label. -->
                        <span class="sr-only">{{ $t('Unread') }}</span>
                    </template>
                </Button>
            </template>
        </div>
        <CreateTeamModal>
            <Button
                variant="ghost"
                size="icon"
                :title="$t('New team')"
                data-test="new-team-trigger"
                class="size-8.5 shrink-0 rounded-[10px] border border-dashed border-sidebar-border text-sidebar-foreground/70 transition-colors hover:bg-sidebar hover:text-sidebar-foreground"
            >
                <Plus class="size-3.75" />
                <span class="sr-only">{{ $t('New team') }}</span>
            </Button>
        </CreateTeamModal>

        <span aria-hidden="true" class="my-2 h-px w-5 bg-sidebar-border" />

        <Button
            v-for="item in NAV_DESTINATION_GLYPHS"
            :key="item.destination"
            variant="ghost"
            size="icon"
            :data-test="`rail-destination-${item.destination}`"
            :aria-current="active === item.destination ? 'true' : undefined"
            :title="$t(item.label)"
            class="relative size-9.5 rounded-[11px] transition-colors"
            :class="glyphClass(item.destination)"
            @click="emit('select', item.destination)"
        >
            <component :is="item.icon" class="size-4.5" />
            <span class="sr-only">{{ $t(item.label) }}</span>
            <span
                v-if="item.destination === 'threads' && hasUnreadThreads"
                data-test="rail-threads-unread-dot"
                aria-hidden="true"
                class="absolute top-1.25 right-1.25 size-1.75 rounded-full bg-brass ring-2 ring-sidebar-rail"
            />
        </Button>

        <span class="flex-1" />

        <!-- The "You" destination is a popover here rather than a panel swap:
             the design keeps the conversation list live behind it, so the rail's
             own avatar is the anchor and `?nav=` stays where it was. Below `md`
             the tab bar takes the same menu full-panel instead. -->
        <UserMenuPopover @invite="emit('invite')" @join="emit('join')">
            <Button
                variant="ghost"
                size="icon"
                data-test="rail-destination-you"
                :aria-current="active === 'you' ? 'true' : undefined"
                :title="$t('You')"
                class="relative size-9.5 rounded-full transition-colors data-[state=open]:bg-sidebar data-[state=open]:text-sidebar-foreground data-[state=open]:shadow-sm"
                :class="glyphClass('you')"
            >
                <span class="relative">
                    <Avatar class="size-8.5 rounded-full">
                        <AvatarImage
                            v-if="hasAvatar"
                            :src="user.avatar!"
                            :alt="''"
                        />
                        <AvatarFallback
                            class="rounded-full bg-brass/30 text-[11px] font-semibold text-foreground"
                        >
                            {{ getInitials(user.name) }}
                        </AvatarFallback>
                    </Avatar>
                    <PresenceDot
                        data-test="rail-presence"
                        :presence="presence"
                        :is-dnd="isDnd"
                        surface-class="bg-sidebar-rail"
                        size="36"
                        class="ring-sidebar-rail"
                    />
                </span>
                <span class="sr-only">{{ $t('You') }}</span>
            </Button>
        </UserMenuPopover>
    </nav>
</template>
