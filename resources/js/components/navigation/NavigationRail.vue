<script setup lang="ts">
import { Plus } from '@lucide/vue';
import { computed } from 'vue';
import CreateTeamModal from '@/components/CreateTeamModal.vue';
import { NAV_DESTINATION_GLYPHS } from '@/components/navigation/destinations';
import PresenceDot from '@/components/PresenceDot.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { useInitials } from '@/composables/useInitials';
import type { NavDestination } from '@/composables/useNavPanel';
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
    /** The open workspace, tiled at the top; absent off a workspace. */
    currentTeam: Team | null;
    /** The viewer's own effective presence, for the avatar's corner dot. */
    presence: RenderedPresence;
    /** Whether the viewer is in do-not-disturb, drawn as the dot's crescent. */
    isDnd: boolean;
    /** Whether any followed thread is unread, dotting the threads glyph. */
    hasUnreadThreads: boolean;
}>();

const emit = defineEmits<{ select: [destination: NavDestination] }>();

const { getInitials } = useInitials();

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
        <!-- Workspace identity, not a control: the switcher lives in the panel
             header beside it, so the tile would only repeat a name assistive
             technology already reached. Child #2 turns it into the workspace
             sheet's third anchor. -->
        <span
            v-if="currentTeam"
            data-test="rail-workspace-tile"
            aria-hidden="true"
            :title="currentTeam.name"
            class="flex size-8.5 shrink-0 items-center justify-center rounded-[10px] bg-sidebar-primary text-[11px] font-semibold text-sidebar-primary-foreground"
            >{{ getInitials(currentTeam.name) }}</span
        >
        <CreateTeamModal>
            <Button
                variant="ghost"
                size="icon"
                :title="$t('New team')"
                data-test="rail-new-team-trigger"
                class="size-8.5 rounded-[10px] border border-dashed border-sidebar-border text-sidebar-foreground/70 transition-colors hover:bg-sidebar hover:text-sidebar-foreground"
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

        <Button
            variant="ghost"
            size="icon"
            data-test="rail-destination-you"
            :aria-current="active === 'you' ? 'true' : undefined"
            :title="$t('You')"
            class="relative size-9.5 rounded-full transition-colors"
            :class="glyphClass('you')"
            @click="emit('select', 'you')"
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
    </nav>
</template>
