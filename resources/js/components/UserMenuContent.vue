<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Compass,
    Keyboard,
    LogOut,
    Monitor,
    Moon,
    PanelLeft,
    PanelRight,
    Settings,
    SmilePlus,
    Sun,
    X,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import { toast } from 'vue-sonner';
import { destroy as destroyStatus } from '@/actions/App/Http/Controllers/Settings/StatusController';
import MenuSegmentedControl from '@/components/MenuSegmentedControl.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuShortcut,
} from '@/components/ui/dropdown-menu';
import UserStatusEmoji from '@/components/UserStatusEmoji.vue';
import { useAppearance } from '@/composables/useAppearance';
import { useInitials } from '@/composables/useInitials';
import { useKeyboardShortcutsModal } from '@/composables/useKeyboardShortcutsModal';
import { useOnboardingTour } from '@/composables/useOnboardingTour';
import { useSidebarPosition } from '@/composables/useSidebarPosition';
import { useTranslations } from '@/composables/useTranslations';
import { useUpdateStatus } from '@/composables/useUpdateStatus';
import { useUserStatusDialog } from '@/composables/useUserStatusDialog';
import { formatTimeOfDay } from '@/lib/datetime';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { Appearance, SidebarPosition, Team, User } from '@/types';

type Props = {
    user: User;
};

const page = usePage();
const { getInitials } = useInitials();
const { t } = useTranslations();
const { open: openKeyboardShortcuts } = useKeyboardShortcutsModal();
const { open: replayOnboardingTour } = useOnboardingTour();
const { open: openStatusDialog } = useUserStatusDialog();

// Quick theme + sidebar switchers reuse the same composables (and shared
// `sidebarPositions` prop) as Settings → Appearance, so flipping either here
// reflects there and back with no extra persistence.
const { appearance, updateAppearance } = useAppearance();
const { sidebarPosition, updateSidebarPosition } = useSidebarPosition();

const themeOptions = computed<
    { value: Appearance; label: string; icon: Component }[]
>(() => [
    { value: 'light', label: t('Light'), icon: Sun },
    { value: 'dark', label: t('Dark'), icon: Moon },
    { value: 'system', label: t('System'), icon: Monitor },
]);

const sidebarIcons: Record<SidebarPosition, Component> = {
    left: PanelLeft,
    right: PanelRight,
};

const sidebarOptions = computed(() =>
    page.props.sidebarPositions.map((option) => ({
        ...option,
        icon: sidebarIcons[option.value],
    })),
);

// The menu footer always shows the running version; when behind it becomes a
// link to the release notes, so the fact stays reachable after the dock strip
// is dismissed. Not dismissible.
const { status, isBehind } = useUpdateStatus();
const appName = computed(() => page.props.name);

const props = defineProps<Props>();

const currentTeam = computed(() => page.props.currentTeam as Team | null);
const hasAvatar = computed(
    () => !!props.user.avatar && props.user.avatar !== '',
);

// The viewer's own live status, read from the shared `auth.user` prop rather
// than the `user` prop so it tracks a set/clear without the menu remounting.
const ownStatus = computed(() => page.props.auth.user.status ?? null);

// When the status clears, as a time of day in the viewer's own zone. Null for a
// status that never clears, which then shows no second line at all.
const clearsAt = computed(() =>
    ownStatus.value?.expiresAt
        ? formatTimeOfDay(
              ownStatus.value.expiresAt,
              page.props.auth.user.timezone ?? undefined,
          )
        : null,
);

/**
 * Clear the status outright from the menu row's ✕, with no trip through the
 * dialog — the one-tap undo for "that meeting ended early".
 */
function clearStatus(): void {
    router.delete(destroyStatus().url, {
        preserveScroll: true,
        onError: () => toast.error(t('Could not clear your status.')),
    });
}

const handleLogout = () => {
    router.flushAll();
};
</script>

<template>
    <!-- Editorial masthead: serif name + italic email over a brass rule that
         carries the workspace eyebrow, on a lifted tinted band. -->
    <DropdownMenuLabel class="border-b border-border bg-muted p-0 font-normal">
        <div class="px-4 pt-4 pb-3.5">
            <div class="flex items-center gap-3">
                <span class="relative shrink-0">
                    <Avatar class="size-10.5 rounded-full">
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
                    <span
                        aria-hidden="true"
                        class="absolute right-0 bottom-0 size-2.75 rounded-full border-2 border-muted bg-emerald-600"
                    />
                </span>
                <div class="min-w-0 flex-1">
                    <!-- The name gains the inline status emoji, previewing
                         exactly what teammates see beside it. -->
                    <div class="flex items-center gap-1.5">
                        <span
                            class="truncate font-serif text-[19px] leading-tight font-semibold tracking-[-0.01em] text-foreground"
                        >
                            {{ user.name }}
                        </span>
                        <UserStatusEmoji
                            :status="ownStatus"
                            :name="user.name"
                            class="text-sm"
                        />
                    </div>
                    <div class="mt-0.5 truncate text-xs text-muted-foreground">
                        {{ user.email }}
                    </div>
                </div>
            </div>
            <div v-if="currentTeam" class="mt-2.75 flex items-center gap-2">
                <span
                    aria-hidden="true"
                    class="h-0.5 w-6.5 shrink-0 rounded-full bg-brass"
                />
                <span
                    class="truncate text-[10.5px] font-semibold tracking-[0.1em] text-muted-foreground uppercase"
                    >{{ currentTeam.name }}</span
                >
            </div>
        </div>
    </DropdownMenuLabel>

    <!-- Presence: the status row, and the entry point for everything the
         presence menu will carry. With nothing set it is a plain "Set a status"
         item; once set it becomes a card showing the status and when it clears,
         with an inline ✕ that clears it outright (no dialog). Clicking the card
         body reopens the dialog to edit. -->
    <div class="px-2 pt-3.5 pb-1">
        <DropdownMenuLabel
            class="px-2.5 pb-1.5 text-[10px] font-semibold tracking-[0.12em] text-muted-foreground uppercase"
            >{{ $t('Status') }}</DropdownMenuLabel
        >
        <DropdownMenuItem
            v-if="!ownStatus"
            class="group/item flex h-9 cursor-pointer items-center gap-2.5 rounded-[10px] px-2.5 py-0 text-[13.5px] font-normal text-foreground focus:bg-primary focus:text-primary-foreground"
            data-test="set-status-menu-item"
            @select="openStatusDialog"
        >
            <SmilePlus
                class="size-3.75 text-muted-foreground group-focus/item:text-brass"
            />
            {{ $t('Set a status') }}
        </DropdownMenuItem>
        <div
            v-else
            class="flex min-h-11 items-center gap-2.5 rounded-[10px] border border-border bg-muted px-2.5 py-1.5"
        >
            <DropdownMenuItem
                :as-child="true"
                class="min-w-0 flex-1 rounded-md p-0 focus:bg-transparent"
                data-test="edit-status-menu-item"
                @select="openStatusDialog"
            >
                <Button
                    variant="unstyled"
                    size="none"
                    type="button"
                    class="flex min-w-0 flex-1 cursor-pointer items-center gap-2.5 text-left"
                >
                    <UserStatusEmoji
                        :status="ownStatus"
                        :name="user.name"
                        class="text-base"
                    />
                    <span class="flex min-w-0 flex-col">
                        <span
                            class="truncate text-[13px] font-semibold text-foreground"
                            >{{ ownStatus.text ?? $t('Status set') }}</span
                        >
                        <span
                            v-if="clearsAt"
                            class="truncate font-serif text-[11px] text-muted-foreground italic"
                            >{{
                                $t('clears at :time', { time: clearsAt })
                            }}</span
                        >
                    </span>
                </Button>
            </DropdownMenuItem>
            <DropdownMenuItem
                :as-child="true"
                class="shrink-0 rounded-full p-0 focus:bg-transparent"
                data-test="clear-status-menu-item"
                @select="clearStatus"
            >
                <Button
                    variant="unstyled"
                    size="none"
                    type="button"
                    :aria-label="$t('Clear status')"
                    class="flex size-5.5 cursor-pointer items-center justify-center rounded-full bg-secondary text-muted-foreground hover:text-foreground"
                >
                    <X class="size-2.75" />
                </Button>
            </DropdownMenuItem>
        </div>
    </div>

    <!-- Appearance: quick theme + sidebar switchers, grouped ahead of navigation.
         Both are label-left / segmented-control-right rows wired to the same
         composables as Settings → Appearance; selecting one applies instantly and
         leaves the menu open. -->
    <div class="px-2 pt-3 pb-1">
        <DropdownMenuLabel
            class="px-2.5 pb-1.5 text-[10px] font-semibold tracking-[0.12em] text-muted-foreground uppercase"
            >{{ $t('Appearance') }}</DropdownMenuLabel
        >
        <div class="flex h-9.5 items-center gap-2.5 px-2.5">
            <span class="flex-1 text-[13px] text-foreground">{{
                $t('Theme')
            }}</span>
            <MenuSegmentedControl
                :model-value="appearance"
                :options="themeOptions"
                :aria-label="$t('Theme')"
                data-test="menu-theme-switcher"
                @update:model-value="
                    (value) => updateAppearance(value as Appearance)
                "
            />
        </div>
        <div class="flex h-9.5 items-center gap-2.5 px-2.5">
            <span class="flex-1 text-[13px] text-foreground">{{
                $t('Sidebar')
            }}</span>
            <MenuSegmentedControl
                :model-value="sidebarPosition"
                :options="sidebarOptions"
                :aria-label="$t('Sidebar position')"
                data-test="menu-sidebar-switcher"
                @update:model-value="
                    (value) => updateSidebarPosition(value as SidebarPosition)
                "
            />
        </div>
    </div>

    <!-- Account -->
    <div class="px-2 pt-3 pb-1.5">
        <DropdownMenuLabel
            class="px-2.5 pb-1.5 text-[10px] font-semibold tracking-[0.12em] text-muted-foreground uppercase"
            >{{ $t('Account') }}</DropdownMenuLabel
        >
        <DropdownMenuItem
            :as-child="true"
            class="group/item flex h-9 cursor-pointer items-center gap-2.5 rounded-[10px] px-2.5 py-0 text-[13.5px] font-normal text-foreground focus:bg-primary focus:text-primary-foreground"
        >
            <Link :href="edit()" data-test="settings-menu-item" prefetch>
                <Settings
                    class="size-3.75 text-muted-foreground group-focus/item:text-brass"
                />
                {{ $t('Settings') }}
            </Link>
        </DropdownMenuItem>
    </div>

    <!-- Help -->
    <div class="px-2 pt-3 pb-2">
        <DropdownMenuLabel
            class="px-2.5 pb-1.5 text-[10px] font-semibold tracking-[0.12em] text-muted-foreground uppercase"
            >{{ $t('Help') }}</DropdownMenuLabel
        >
        <DropdownMenuItem
            class="group/item flex h-9 cursor-pointer items-center gap-2.5 rounded-[10px] px-2.5 py-0 text-[13.5px] font-normal text-foreground focus:bg-primary focus:text-primary-foreground"
            data-test="keyboard-shortcuts-menu-item"
            @select="openKeyboardShortcuts"
        >
            <Keyboard
                class="size-3.75 text-muted-foreground group-focus/item:text-brass"
            />
            {{ $t('Keyboard shortcuts') }}
            <DropdownMenuShortcut
                class="ml-auto inline-flex h-4.5 min-w-4 items-center justify-center rounded-[5px] border border-border px-1 font-mono text-[10px] font-semibold tracking-normal text-muted-foreground group-focus/item:border-primary-foreground/30 group-focus/item:text-primary-foreground"
                >?</DropdownMenuShortcut
            >
        </DropdownMenuItem>
        <DropdownMenuItem
            class="group/item flex h-9 cursor-pointer items-center gap-2.5 rounded-[10px] px-2.5 py-0 text-[13.5px] font-normal text-foreground focus:bg-primary focus:text-primary-foreground"
            data-test="replay-tour-menu-item"
            @select="replayOnboardingTour"
        >
            <Compass
                class="size-3.75 text-muted-foreground group-focus/item:text-brass"
            />
            {{ $t('Replay tour') }}
        </DropdownMenuItem>
    </div>

    <!-- Log out: a quiet outlined pill in its own footer band; deliberate and
         hard to fat-finger. -->
    <div class="border-t border-border bg-muted/50 p-2">
        <DropdownMenuItem
            :as-child="true"
            variant="destructive"
            class="flex h-8.5 w-full cursor-pointer items-center justify-center gap-2 rounded-full border border-border px-3 py-0 text-[12.5px] font-semibold focus:bg-destructive/10"
        >
            <Link
                :href="logout()"
                @click="handleLogout"
                as="button"
                data-test="logout-button"
            >
                <LogOut class="size-3.25" />
                {{ $t('Log out') }}
            </Link>
        </DropdownMenuItem>
    </div>

    <template v-if="status">
        <a
            v-if="isBehind"
            :href="status.notesUrl ?? '#'"
            target="_blank"
            rel="noopener noreferrer"
            data-test="user-menu-version"
            class="flex items-center justify-center gap-1.5 border-t border-border bg-muted/50 px-2 py-1.5 font-mono text-[10.5px]"
        >
            <span aria-hidden="true" class="size-1.5 rounded-full bg-brass" />
            <span class="text-muted-foreground">v{{ status.current }}</span>
            <span class="font-sans font-semibold text-foreground">
                {{
                    $t('Version :version available', {
                        version: status.latest ?? '',
                    })
                }}
            </span>
        </a>
        <div
            v-else
            data-test="user-menu-version"
            class="border-t border-border bg-muted/50 px-2 py-1.5 text-center font-mono text-[10.5px] text-muted-foreground"
        >
            {{ appName }} v{{ status.current }}
        </div>
    </template>
</template>
