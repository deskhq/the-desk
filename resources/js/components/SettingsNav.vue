<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import {
    SidebarGroup,
    SidebarGroupContent,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useSettingsNavItems } from '@/composables/useSettingsNavItems';
import { toUrl } from '@/lib/utils';

const page = usePage();

const { workspaceUrl, navItems, teamAdminNavItems } = useSettingsNavItems();

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <nav
        data-test="settings-nav"
        :aria-label="$t('Settings')"
        class="flex min-w-0 flex-col gap-2"
    >
        <div class="px-2 pt-2">
            <Link
                :href="workspaceUrl"
                data-test="settings-back"
                class="flex h-8 w-full items-center gap-2 rounded-[9px] px-2.5 text-[13px] text-muted-foreground transition-colors hover:bg-sidebar-accent hover:text-sidebar-foreground"
            >
                <ArrowLeft class="size-3.25 shrink-0" />
                <span>{{ $t('Back to workspace') }}</span>
            </Link>
        </div>

        <SidebarGroup class="pt-2">
            <div
                class="flex h-7 items-center px-2 text-[10.5px] font-semibold tracking-[0.1em] text-muted-foreground uppercase"
            >
                {{ $t('Settings') }}
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
                            class="h-7.5 gap-2 rounded-[9px] px-2.5 text-[13px] text-muted-foreground hover:bg-sidebar-accent/60 hover:text-sidebar-foreground data-[active=true]:bg-sidebar-primary data-[active=true]:font-medium data-[active=true]:text-sidebar-primary-foreground data-[active=true]:hover:bg-sidebar-primary data-[active=true]:hover:text-sidebar-primary-foreground"
                        >
                            <Link
                                :href="item.href"
                                :data-test="`settings-nav-${item.slug}`"
                                :aria-current="
                                    isCurrentOrParentUrl(item.href)
                                        ? 'page'
                                        : undefined
                                "
                            >
                                <component :is="item.icon" class="size-3.25" />
                                <span>{{ item.title }}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroupContent>
        </SidebarGroup>

        <!--
            Team-admin evidence group: only rendered when the viewer can reach at
            least one of Audit log / Security log / Exports for the current team.
            The team name heads the group, mirroring the #421 design's team subnav.
        -->
        <SidebarGroup v-if="teamAdminNavItems.length > 0" class="pt-0">
            <div
                class="flex h-7 items-center px-2 text-[10.5px] font-semibold tracking-[0.1em] text-muted-foreground uppercase"
            >
                {{ page.props.currentTeam?.name }}
            </div>
            <SidebarGroupContent>
                <SidebarMenu>
                    <SidebarMenuItem
                        v-for="item in teamAdminNavItems"
                        :key="toUrl(item.href)"
                    >
                        <SidebarMenuButton
                            as-child
                            :is-active="isCurrentOrParentUrl(item.href)"
                            class="h-7.5 gap-2 rounded-[9px] px-2.5 text-[13px] text-muted-foreground hover:bg-sidebar-accent/60 hover:text-sidebar-foreground data-[active=true]:bg-sidebar-primary data-[active=true]:font-medium data-[active=true]:text-sidebar-primary-foreground data-[active=true]:hover:bg-sidebar-primary data-[active=true]:hover:text-sidebar-primary-foreground"
                        >
                            <Link
                                :href="item.href"
                                :data-test="`settings-nav-${item.slug}`"
                                :aria-current="
                                    isCurrentOrParentUrl(item.href)
                                        ? 'page'
                                        : undefined
                                "
                            >
                                <component :is="item.icon" class="size-3.25" />
                                <span>{{ item.title }}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroupContent>
        </SidebarGroup>
    </nav>
</template>
