<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import {
    ArrowLeft,
    Database,
    Languages,
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
import { useTranslations } from '@/composables/useTranslations';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editDataPrivacy } from '@/routes/data-export';
import { edit as editLocale } from '@/routes/locale';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { index as teams } from '@/routes/teams';
import type { NavItem } from '@/types';

const page = usePage();
const { t } = useTranslations();

// The active workspace to fall back to when leaving settings; null-safe for the
// rare state where the user has no current team yet.
const workspaceUrl = computed(() =>
    page.props.currentTeam
        ? channelsWorkspace(page.props.currentTeam.slug).url
        : '/',
);

// `slug` stays locale-independent so `data-test` selectors are stable regardless
// of the active language; only the displayed `title` is translated.
type SettingsNavItem = NavItem & { icon: LucideIcon; slug: string };

const navItems = computed<SettingsNavItem[]>(() => [
    { title: t('Profile'), href: editProfile(), icon: User, slug: 'profile' },
    {
        title: t('Security'),
        href: editSecurity(),
        icon: Shield,
        slug: 'security',
    },
    { title: t('Teams'), href: teams(), icon: Users, slug: 'teams' },
    {
        title: t('Appearance & notifications'),
        href: editAppearance(),
        icon: Palette,
        slug: 'appearance',
    },
    {
        title: t('Language'),
        href: editLocale(),
        icon: Languages,
        slug: 'language',
    },
    {
        title: t('Data & privacy'),
        href: editDataPrivacy(),
        icon: Database,
        slug: 'data-privacy',
    },
]);

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
                <ArrowLeft class="size-[13px] shrink-0" />
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
                            class="h-[30px] gap-2 rounded-[9px] px-2.5 text-[13px] text-muted-foreground hover:bg-sidebar-accent/60 hover:text-sidebar-foreground data-[active=true]:bg-sidebar-primary data-[active=true]:font-medium data-[active=true]:text-sidebar-primary-foreground data-[active=true]:hover:bg-sidebar-primary data-[active=true]:hover:text-sidebar-primary-foreground"
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
                                <component
                                    :is="item.icon"
                                    class="size-[13px]"
                                />
                                <span>{{ item.title }}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroupContent>
        </SidebarGroup>
    </nav>
</template>
