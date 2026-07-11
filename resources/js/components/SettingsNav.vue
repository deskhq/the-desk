<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import {
    ArrowLeft,
    Bell,
    Database,
    Palette,
    Shield,
    User,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import { index as channelsWorkspace } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import {
    SidebarGroup,
    SidebarGroupContent,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editDataPrivacy } from '@/routes/data-export';
import { edit as editNotifications } from '@/routes/notifications';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { index as teams } from '@/routes/teams';
import type { NavItem } from '@/types';

const page = usePage();

// The active workspace to fall back to when leaving settings; null-safe for the
// rare state where the user has no current team yet.
const workspaceUrl = computed(() =>
    page.props.currentTeam
        ? channelsWorkspace(page.props.currentTeam.slug).url
        : '/',
);

type SettingsNavItem = NavItem & { icon: LucideIcon };

const navItems: SettingsNavItem[] = [
    { title: 'Profile', href: editProfile(), icon: User },
    { title: 'Security', href: editSecurity(), icon: Shield },
    { title: 'Teams', href: teams(), icon: Users },
    { title: 'Appearance', href: editAppearance(), icon: Palette },
    { title: 'Notifications', href: editNotifications(), icon: Bell },
    { title: 'Data & privacy', href: editDataPrivacy(), icon: Database },
];

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="px-2 pt-2">
        <Link
            :href="workspaceUrl"
            data-test="settings-back"
            class="flex h-8 w-full items-center gap-2 rounded-[9px] px-2.5 text-[13px] text-muted-foreground transition-colors hover:bg-sidebar-accent hover:text-sidebar-foreground"
        >
            <ArrowLeft class="size-[13px] shrink-0" />
            <span>Back to workspace</span>
        </Link>
    </div>

    <SidebarGroup class="pt-2">
        <div
            class="flex h-7 items-center px-2 text-[10.5px] font-semibold tracking-[0.1em] text-muted-foreground/70 uppercase"
        >
            Settings
        </div>
        <SidebarGroupContent>
            <SidebarMenu>
                <SidebarMenuItem
                    v-for="item in navItems"
                    :key="toUrl(item.href)"
                >
                    <SidebarMenuButton
                        as-child
                        :is-active="isCurrentOrParentUrl(item.href)"
                        class="h-[30px] gap-2 rounded-[9px] px-2.5 text-[13px] text-muted-foreground hover:bg-sidebar-accent/60 hover:text-sidebar-foreground data-[active=true]:bg-sidebar-primary data-[active=true]:font-medium data-[active=true]:text-sidebar-primary-foreground data-[active=true]:hover:bg-sidebar-primary data-[active=true]:hover:text-sidebar-primary-foreground"
                    >
                        <Link
                            :href="item.href"
                            :data-test="`settings-nav-${item.title.toLowerCase()}`"
                        >
                            <component :is="item.icon" class="size-[13px]" />
                            <span>{{ item.title }}</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarGroupContent>
    </SidebarGroup>
</template>
