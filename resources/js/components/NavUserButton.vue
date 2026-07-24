<script setup lang="ts">
import { ChevronsUpDown } from '@lucide/vue';
import { computed } from 'vue';
import PresenceDot from '@/components/PresenceDot.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { SidebarMenuButton } from '@/components/ui/sidebar';
import { useInitials } from '@/composables/useInitials';
import type { RenderedPresence } from '@/lib/presence';
import type { Team, User } from '@/types';

/**
 * The user chip that triggers the user menu — extracted so the dropdown
 * trigger (at `md` and up) and the bottom-sheet trigger (below `md`) render
 * the exact same chip. Listeners and trigger attributes (`data-state`,
 * `aria-*`) fall through to the `SidebarMenuButton` root.
 */
const props = defineProps<{
    user: User;
    currentTeam: Team | null;
    /** The viewer's own effective presence, for the avatar's corner dot. */
    presence: RenderedPresence;
    /** Whether the viewer is in DND right now, drawn as the dot's crescent. */
    isDnd: boolean;
}>();

const { getInitials } = useInitials();

const hasAvatar = computed(
    () => !!props.user.avatar && props.user.avatar !== '',
);
</script>

<template>
    <!-- Resting: cream card on the sand dock. Open: the ink (dark) / cream
         (dark theme) press of the active-channel row, so trigger and menu
         read as one unit. -->
    <SidebarMenuButton
        class="group/nav-user h-11 gap-2.25 rounded-[11px] border border-secondary bg-popover px-2 py-0 hover:bg-secondary hover:text-foreground data-[state=open]:border-transparent data-[state=open]:bg-sidebar-primary data-[state=open]:text-sidebar-primary-foreground data-[state=open]:shadow-[0_2px_6px_rgba(29,26,21,0.25)] data-[state=open]:hover:bg-sidebar-primary"
        data-test="sidebar-menu-button"
    >
        <span class="relative shrink-0">
            <Avatar class="size-7.5 rounded-full">
                <AvatarImage
                    v-if="hasAvatar"
                    :src="user.avatar!"
                    :alt="user.name"
                />
                <AvatarFallback
                    class="rounded-full bg-brass/30 text-[10.5px] font-semibold text-foreground group-data-[state=open]/nav-user:text-sidebar-primary-foreground"
                >
                    {{ getInitials(user.name) }}
                </AvatarFallback>
            </Avatar>
            <!-- The halo tracks the chip's own surface, which changes on hover
                 and while the menu is open, so an away dot's hollow centre
                 keeps matching what is actually behind it. -->
            <PresenceDot
                data-test="nav-user-presence"
                :presence="presence"
                :is-dnd="isDnd"
                surface-class="bg-popover group-hover/nav-user:bg-secondary group-data-[state=open]/nav-user:bg-sidebar-primary"
                size="30"
                class="ring-popover group-hover/nav-user:ring-secondary group-data-[state=open]/nav-user:ring-sidebar-primary"
            />
        </span>
        <span class="grid min-w-0 flex-1 text-left leading-tight">
            <span
                class="truncate text-[13px] font-semibold text-foreground group-data-[state=open]/nav-user:text-sidebar-primary-foreground"
                >{{ user.name }}</span
            >
            <span
                v-if="currentTeam"
                class="truncate font-serif text-[11px] text-muted-foreground italic group-data-[state=open]/nav-user:text-brass"
                >{{ currentTeam.name }}</span
            >
        </span>
        <ChevronsUpDown
            class="ml-auto size-3.25 text-muted-foreground group-data-[state=open]/nav-user:text-brass"
        />
    </SidebarMenuButton>
</template>
