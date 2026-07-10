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
                    <SidebarMenuButton
                        class="h-auto gap-2.5 rounded-[10px] bg-sidebar-accent/70 p-1.5 hover:bg-sidebar-accent data-[state=open]:bg-sidebar-accent"
                        data-test="sidebar-menu-button"
                    >
                        <span class="relative shrink-0">
                            <Avatar class="size-7 rounded-full">
                                <AvatarImage
                                    v-if="hasAvatar"
                                    :src="user.avatar!"
                                    :alt="user.name"
                                />
                                <AvatarFallback
                                    class="rounded-full bg-brass-fill text-[10px] font-semibold text-sidebar-foreground"
                                >
                                    {{ getInitials(user.name) }}
                                </AvatarFallback>
                            </Avatar>
                            <span
                                aria-hidden="true"
                                class="absolute -right-0.5 -bottom-0.5 size-[9px] rounded-full border-2 border-sidebar-accent bg-emerald-600"
                            />
                        </span>
                        <span class="grid flex-1 text-left leading-tight">
                            <span
                                class="truncate text-[12.5px] font-medium text-sidebar-foreground"
                                >{{ user.name }}</span
                            >
                            <span
                                v-if="currentTeam"
                                class="truncate text-[10.5px] text-muted-foreground"
                                >{{ currentTeam.name }}</span
                            >
                        </span>
                        <ChevronsUpDown
                            class="ml-auto size-[13px] text-muted-foreground"
                        />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="w-(--reka-dropdown-menu-trigger-width) min-w-56 rounded-lg"
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
