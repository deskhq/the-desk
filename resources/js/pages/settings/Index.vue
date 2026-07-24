<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, LogOut, Users } from '@lucide/vue';
import { computed, onMounted, watch } from 'vue';
import PresenceDot from '@/components/PresenceDot.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import UserStatusEmoji from '@/components/UserStatusEmoji.vue';
import { useInitials } from '@/composables/useInitials';
import { useIsMobile } from '@/composables/useIsMobile';
import { useSettingsNavItems } from '@/composables/useSettingsNavItems';
import { useTranslations } from '@/composables/useTranslations';
import { useUpdateStatus } from '@/composables/useUpdateStatus';
import { useUserStatusDialog } from '@/composables/useUserStatusDialog';
import { isDndActiveNow } from '@/lib/dnd';
import { presenceLabelKey } from '@/lib/presence';
import type { RenderedPresence } from '@/lib/presence';
import { logout } from '@/routes';
import { edit as editProfile } from '@/routes/profile';
import { index as teamsIndex } from '@/routes/teams';

defineProps<{
    sessionsCount: number;
}>();

const page = usePage();
const { t } = useTranslations();
const { getInitials } = useInitials();
const { open: openStatusDialog } = useUserStatusDialog();
const { workspaceUrl, navItems, teamAdminNavItems } = useSettingsNavItems();

// The index exists for the one-pane world below the breakpoint; from md up the
// two-pane layout owns navigation, so a desktop visit (a typed URL, a resize
// past the breakpoint) moves along to the first pane instead of lingering on a
// list the side nav already renders. Watched from onMounted so the server-side
// render never navigates.
const isMobile = useIsMobile();

onMounted(() => {
    watch(
        isMobile,
        (mobile) => {
            if (!mobile) {
                router.visit(editProfile(), { replace: true });
            }
        },
        { immediate: true },
    );
});

const user = computed(() => page.props.auth.user);
const currentTeam = computed(() => page.props.currentTeam);

const hasAvatar = computed(() => !!user.value.avatar && user.value.avatar !== '');

/** The viewer's own live status, from the shared prop so it tracks a set/clear. */
const ownStatus = computed(() => page.props.auth.user.status ?? null);

/** Never "offline" — the viewer is plainly here, mirroring the user menu. */
const ownPresence = computed<RenderedPresence>(
    () => page.props.auth.user.presence ?? 'active',
);

const isDnd = computed(() =>
    isDndActiveNow(
        page.props.auth.user.dnd ?? null,
        page.props.auth.user.timezone ?? null,
    ),
);

/** The account rows: every personal pane; Teams moves to the workspace card. */
const accountItems = computed(() =>
    navItems.value.filter((item) => item.slug !== 'teams'),
);

// The version line pinned at the index foot, matching the user-menu footer.
const { status } = useUpdateStatus();
const appName = computed(() => page.props.name);
</script>

<template>
    <Head :title="$t('Settings')" />

    <div
        data-test="settings-index"
        class="flex min-h-full flex-col gap-3.5 p-3.5 pt-0 md:hidden"
    >
        <header
            class="-mx-3.5 mb-0.5 flex shrink-0 items-center gap-2 border-b border-border px-3.5 py-2.5"
        >
            <Button
                as-child
                variant="ghost"
                size="icon"
                data-test="settings-index-back"
                class="-ml-1.5 size-11 shrink-0 rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground"
            >
                <Link
                    :href="workspaceUrl"
                    :aria-label="$t('Back to workspace')"
                >
                    <ChevronLeft class="size-4.5" />
                </Link>
            </Button>
            <h1
                class="min-w-0 flex-1 truncate font-serif text-[22px] leading-none font-semibold tracking-tight"
            >
                {{ $t('Settings') }}
            </h1>
        </header>

        <Link
            :href="editProfile()"
            data-test="settings-index-identity"
            class="flex min-h-11 items-center gap-3 rounded-[14px] bg-muted p-3"
        >
            <span class="relative shrink-0">
                <Avatar class="size-11.5 rounded-full">
                    <AvatarImage
                        v-if="hasAvatar"
                        :src="user.avatar!"
                        :alt="user.name"
                    />
                    <AvatarFallback
                        class="rounded-full bg-brass/30 text-sm font-semibold text-foreground"
                    >
                        {{ getInitials(user.name) }}
                    </AvatarFallback>
                </Avatar>
                <PresenceDot
                    :presence="ownPresence"
                    :is-dnd="isDnd"
                    surface-class="bg-muted"
                    size="48"
                    class="ring-muted"
                />
            </span>
            <span class="min-w-0 flex-1">
                <span class="block truncate text-[15.5px] font-semibold">{{
                    user.name
                }}</span>
                <span class="block truncate text-xs text-muted-foreground">
                    <template v-if="currentTeam"
                        >{{ user.email }} · {{ currentTeam.name }}</template
                    >
                    <template v-else>{{ user.email }}</template>
                </span>
            </span>
            <ChevronRight
                class="size-3.5 shrink-0 text-muted-foreground/70"
                aria-hidden="true"
            />
        </Link>

        <Button
            variant="unstyled"
            size="none"
            type="button"
            data-test="settings-index-status"
            class="flex min-h-11 w-full cursor-pointer items-center gap-2.5 rounded-xl border border-border bg-background px-3.5 text-left"
            @click="openStatusDialog"
        >
            <UserStatusEmoji
                v-if="ownStatus"
                :status="ownStatus"
                :name="user.name"
                class="text-base"
                decorative
            />
            <span v-else aria-hidden="true" class="text-base">😊</span>
            <span
                class="min-w-0 flex-1 truncate text-sm"
                :class="
                    ownStatus ? 'text-foreground' : 'text-muted-foreground'
                "
                >{{ ownStatus?.text ?? $t('Update your status…') }}</span
            >
            <span
                data-test="settings-index-presence"
                class="inline-flex h-6.5 shrink-0 items-center rounded-full bg-muted px-2.5 text-xs font-semibold text-muted-foreground"
                >{{ $t(presenceLabelKey(ownPresence)) }}</span
            >
        </Button>

        <nav
            :aria-label="$t('Settings')"
            class="flex flex-col gap-3.5"
        >
            <div
                class="divide-y divide-border overflow-hidden rounded-[14px] border border-border"
            >
                <Link
                    v-for="item in accountItems"
                    :key="item.slug"
                    :href="item.href"
                    :data-test="`settings-index-row-${item.slug}`"
                    class="flex min-h-13 items-center gap-3 px-3.5 transition-colors hover:bg-muted/60"
                >
                    <component
                        :is="item.icon"
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span
                        class="min-w-0 flex-1 truncate text-[14.5px] font-medium"
                        >{{ item.title }}</span
                    >
                    <span
                        v-if="item.slug === 'security'"
                        class="shrink-0 text-xs text-muted-foreground"
                        >{{
                            sessionsCount === 1
                                ? $t('1 session')
                                : $t(':count sessions', {
                                      count: String(sessionsCount),
                                  })
                        }}</span
                    >
                    <ChevronRight
                        class="size-3.5 shrink-0 text-muted-foreground/70"
                        aria-hidden="true"
                    />
                </Link>
            </div>

            <div
                class="divide-y divide-border overflow-hidden rounded-[14px] border border-border"
            >
                <Link
                    :href="teamsIndex()"
                    data-test="settings-index-row-teams"
                    class="flex min-h-13 items-center gap-3 px-3.5 transition-colors hover:bg-muted/60"
                >
                    <Users
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span
                        class="min-w-0 flex-1 truncate text-[14.5px] font-medium"
                        >{{
                            currentTeam
                                ? $t('Workspace · :team', {
                                      team: currentTeam.name,
                                  })
                                : $t('Teams')
                        }}</span
                    >
                    <span
                        v-if="currentTeam?.roleLabel"
                        data-test="settings-index-role"
                        class="inline-flex h-5 shrink-0 items-center rounded-full border border-brass-border/50 bg-brass-fill px-2 text-[10.5px] font-semibold text-brass-fill-foreground"
                        >{{ currentTeam.roleLabel }}</span
                    >
                    <ChevronRight
                        class="size-3.5 shrink-0 text-muted-foreground/70"
                        aria-hidden="true"
                    />
                </Link>
                <Link
                    v-for="item in teamAdminNavItems"
                    :key="item.slug"
                    :href="item.href"
                    :data-test="`settings-index-row-${item.slug}`"
                    class="flex min-h-13 items-center gap-3 px-3.5 transition-colors hover:bg-muted/60"
                >
                    <component
                        :is="item.icon"
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span
                        class="min-w-0 flex-1 truncate text-[14.5px] font-medium"
                        >{{ item.title }}</span
                    >
                    <ChevronRight
                        class="size-3.5 shrink-0 text-muted-foreground/70"
                        aria-hidden="true"
                    />
                </Link>
                <Link
                    :href="logout()"
                    as="button"
                    data-test="settings-index-logout"
                    class="flex min-h-13 w-full cursor-pointer items-center gap-3 px-3.5 text-left transition-colors hover:bg-destructive/10"
                    @click="router.flushAll()"
                >
                    <LogOut
                        class="size-4 shrink-0 text-destructive-text"
                        aria-hidden="true"
                    />
                    <span
                        class="min-w-0 flex-1 truncate text-[14.5px] font-medium text-destructive-text"
                        >{{ $t('Log out') }}</span
                    >
                </Link>
            </div>
        </nav>

        <div
            data-test="settings-index-version"
            class="mt-auto pb-1 text-center text-[11.5px] text-muted-foreground"
        >
            <template v-if="status"
                >{{ appName }} · v{{ status.current }}</template
            >
            <template v-else>{{ appName }}</template>
        </div>
    </div>

    <!-- From md up the index never settles (the mounted watcher moves along to
         the profile pane); this quiet placeholder only bridges that redirect. -->
    <div class="hidden md:block" aria-hidden="true"></div>
</template>
