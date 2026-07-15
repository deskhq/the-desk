<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ChevronsUpDown } from '@lucide/vue';
import { computed } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useInitials } from '@/composables/useInitials';
import type { Team } from '@/types';

const page = usePage();
const user = page.props.auth.user;
const { isMobile, state } = useSidebar();
const { getInitials } = useInitials();

const currentTeam = computed(() => page.props.currentTeam as Team | null);
const hasAvatar = computed(() => !!user.avatar && user.avatar !== '');
</script>

<template>
    <SidebarMenu>
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <!-- Resting: cream card on the sand dock. Open: the ink
                         (dark) / cream (dark theme) press of the active-channel
                         row, so trigger and menu read as one unit. -->
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
                            <span
                                aria-hidden="true"
                                class="absolute -right-0.5 -bottom-0.5 size-2.25 rounded-full border-2 border-popover bg-emerald-600 group-hover/nav-user:border-secondary group-data-[state=open]/nav-user:border-sidebar-primary"
                            />
                        </span>
                        <span
                            class="grid min-w-0 flex-1 text-left leading-tight"
                        >
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
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="w-(--reka-dropdown-menu-trigger-width) min-w-64 rounded-2xl border-border bg-popover p-0 shadow-[0_12px_32px_rgba(60,55,40,0.16)] dark:shadow-[0_16px_40px_rgba(0,0,0,0.5)]"
                    :side="
                        isMobile
                            ? 'bottom'
                            : state === 'collapsed'
                              ? 'left'
                              : 'bottom'
                    "
                    align="end"
                    :side-offset="4"
                >
                    <UserMenuContent :user="user" />
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
