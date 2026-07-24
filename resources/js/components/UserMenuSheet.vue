<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    ChevronRight,
    Compass,
    LogOut,
    Monitor,
    Moon,
    Settings,
    SmilePlus,
    Sun,
    X,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';
import MenuSegmentedControl from '@/components/MenuSegmentedControl.vue';
import PresenceDot from '@/components/PresenceDot.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import UserStatusEmoji from '@/components/UserStatusEmoji.vue';
import { useAppearance } from '@/composables/useAppearance';
import { useDndPauseDialog } from '@/composables/useDndPauseDialog';
import { useInitials } from '@/composables/useInitials';
import { useOnboardingTour } from '@/composables/useOnboardingTour';
import { useTranslations } from '@/composables/useTranslations';
import { useUpdateStatus } from '@/composables/useUpdateStatus';
import { useUserMenu } from '@/composables/useUserMenu';
import { useUserStatusDialog } from '@/composables/useUserStatusDialog';
import { dndPauseLabel } from '@/lib/dndPause';
import type { DndPauseKey } from '@/lib/dndPause';
import { presenceLabelKey } from '@/lib/presence';
import { logout } from '@/routes';
import { edit as appearanceEdit } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import type { Appearance, User } from '@/types';

const props = defineProps<{
    /** Whether the sheet is presented. */
    open: boolean;
    user: User;
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
}>();

const page = usePage();
const { t } = useTranslations();
const { getInitials } = useInitials();
const { open: openStatusDialog } = useUserStatusDialog();
const { open: openDndPauseDialog } = useDndPauseDialog();
const { open: replayOnboardingTour } = useOnboardingTour();
const { appearance, updateAppearance } = useAppearance();
const { status, isBehind } = useUpdateStatus();

const {
    currentTeam,
    ownStatus,
    ownPresence,
    togglesTo,
    isDnd,
    pausedUntil,
    quietHoursUntil,
    clearsAt,
    pausePresets,
    clearStatus,
    togglePresence,
    choosePause,
    resumeNotifications,
    snoozeSchedule,
    handleLogout,
} = useUserMenu();

const appName = computed(() => page.props.name);
const hasAvatar = computed(
    () => !!props.user.avatar && props.user.avatar !== '',
);

const themeOptions = computed<
    { value: Appearance; label: string; icon: Component }[]
>(() => [
    { value: 'light', label: t('Light'), icon: Sun },
    { value: 'dark', label: t('Dark'), icon: Moon },
    { value: 'system', label: t('System'), icon: Monitor },
]);

/** The DND presets present as a second sheet over this one, never a flyout. */
const presetsOpen = ref(false);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            presetsOpen.value = false;
        }
    },
);

function close(): void {
    emit('update:open', false);
}

/** Trade the sheet for the status dialog (itself a sheet below `md`). */
function openStatus(): void {
    openStatusDialog();
    close();
}

/** Apply a preset in place: the second sheet retreats, the paused card grows. */
function choosePreset(key: DndPauseKey): void {
    choosePause(key);
    presetsOpen.value = false;
}

/** Both sheets retreat together — for the rows that leave the menu behind. */
function closeAll(): void {
    presetsOpen.value = false;
    close();
}

/** Trade both sheets for the custom-pause dialog. */
function openCustomPause(): void {
    openDndPauseDialog();
    closeAll();
}

function replayTour(): void {
    replayOnboardingTour();
    close();
}

/** The shared look of one 46px sheet row (design m8). */
const rowClass =
    'flex h-11.5 w-full items-center gap-2.5 rounded-[11px] px-2.5 text-left text-sm font-normal text-foreground transition-colors hover:bg-muted/50 active:bg-muted';

/** The shared look of one uppercase section label (design m8). */
const sectionLabelClass =
    'px-2.5 pb-1.5 text-[10px] font-semibold tracking-[0.12em] text-muted-foreground uppercase';

/**
 * The footer bands reach the physical bottom edge: the sheet primitive pads
 * every sheet's bottom uniformly, so the last band swallows that padding with
 * a negative margin and re-applies only the safe-area part inside itself.
 */
const footerStyle = {
    marginBottom: 'calc(-1.5rem - env(safe-area-inset-bottom))',
    paddingBottom: 'calc(0.875rem + env(safe-area-inset-bottom))',
};
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent
            data-test="user-menu-sheet"
            :show-close-button="false"
            class="gap-0 p-0"
        >
            <DialogTitle class="sr-only">{{ $t('User menu') }}</DialogTitle>
            <DialogDescription class="sr-only">
                {{ $t('Your status, appearance and account.') }}
            </DialogDescription>

            <!-- Editorial masthead on a tinted band, matching the desktop
                 dropdown: serif name with the inline status emoji, the
                 presence readout ahead of the address, and the brass rule
                 carrying the workspace eyebrow. -->
            <div class="border-b border-border bg-muted px-4 pt-3.5 pb-3.5">
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
                        <PresenceDot
                            data-test="user-menu-presence"
                            :presence="ownPresence"
                            :is-dnd="isDnd"
                            surface-class="bg-muted"
                            size="42"
                            class="ring-muted"
                        />
                    </span>
                    <div class="min-w-0 flex-1">
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
                                decorative
                            />
                        </div>
                        <div
                            class="mt-0.5 flex min-w-0 items-baseline gap-1.5 text-xs text-muted-foreground"
                        >
                            <!-- In DND the readout names the pause instead of
                                 the presence, in the same italic serif as away. -->
                            <span
                                data-test="user-menu-presence-label"
                                class="shrink-0 font-medium"
                                :class="
                                    isDnd || ownPresence === 'away'
                                        ? 'font-serif text-muted-foreground italic'
                                        : 'text-emerald-700 dark:text-emerald-400'
                                "
                                >{{
                                    isDnd
                                        ? $t('Notifications paused')
                                        : $t(presenceLabelKey(ownPresence))
                                }}</span
                            >
                            <span aria-hidden="true" class="shrink-0"
                                >&middot;</span
                            >
                            <span class="truncate">{{ user.email }}</span>
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

            <!-- Status: the current custom status as a bordered card (or the
                 plain set-a-status row), the away toggle, and the pause row
                 whose presets open as a second sheet. -->
            <div class="px-2 pt-3 pb-1">
                <div :class="sectionLabelClass">{{ $t('Status') }}</div>
                <div
                    v-if="isDnd"
                    data-test="dnd-paused-card"
                    class="mb-1 flex min-h-12 items-center gap-2.5 rounded-[11px] border border-border bg-muted px-2.5 py-1.5"
                >
                    <Moon class="size-4 shrink-0 text-muted-foreground" />
                    <span class="flex min-w-0 flex-1 flex-col">
                        <span
                            class="truncate text-[13px] font-semibold text-foreground"
                            >{{ $t('Paused') }}</span
                        >
                        <span
                            v-if="pausedUntil"
                            data-test="dnd-paused-until"
                            class="truncate font-serif text-[11px] text-muted-foreground italic"
                            >{{
                                $t('until :time', { time: pausedUntil })
                            }}</span
                        >
                        <span
                            v-else-if="quietHoursUntil"
                            data-test="dnd-paused-until"
                            class="truncate font-serif text-[11px] text-muted-foreground italic"
                            >{{
                                $t('quiet hours · until :time', {
                                    time: quietHoursUntil,
                                })
                            }}</span
                        >
                    </span>
                    <Button
                        v-if="pausedUntil"
                        variant="unstyled"
                        size="none"
                        type="button"
                        data-test="dnd-resume-menu-item"
                        class="inline-flex h-7 shrink-0 cursor-pointer items-center rounded-full border border-border px-3 text-[11.5px] font-semibold text-muted-foreground hover:text-foreground"
                        @click="resumeNotifications()"
                    >
                        {{ $t('Resume') }}
                    </Button>
                    <Button
                        v-else-if="quietHoursUntil"
                        variant="unstyled"
                        size="none"
                        type="button"
                        data-test="dnd-snooze-menu-item"
                        class="inline-flex h-7 shrink-0 cursor-pointer items-center rounded-full border border-border px-3 text-[11.5px] font-semibold text-muted-foreground hover:text-foreground"
                        @click="snoozeSchedule()"
                    >
                        {{ $t('Snooze schedule today') }}
                    </Button>
                </div>
                <Button
                    v-if="!ownStatus"
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="set-status-menu-item"
                    :class="rowClass"
                    @click="openStatus"
                >
                    <SmilePlus class="size-3.75 text-muted-foreground" />
                    <span class="min-w-0 flex-1 truncate">{{
                        $t('Set a status')
                    }}</span>
                </Button>
                <div
                    v-else
                    class="flex min-h-12 items-center gap-2.5 rounded-[11px] border border-border bg-muted px-2.5 py-1.5"
                >
                    <Button
                        variant="unstyled"
                        size="none"
                        type="button"
                        data-test="edit-status-menu-item"
                        class="flex min-w-0 flex-1 cursor-pointer items-center gap-2.5 text-left"
                        @click="openStatus"
                    >
                        <UserStatusEmoji
                            :status="ownStatus"
                            :name="user.name"
                            class="text-base"
                            decorative
                        />
                        <span class="flex min-w-0 flex-col">
                            <span
                                class="truncate text-[13px] font-semibold text-foreground"
                                >{{ ownStatus.text ?? $t('Status set') }}</span
                            >
                            <span
                                v-if="clearsAt"
                                class="truncate font-serif text-[11.5px] text-muted-foreground italic"
                                >{{
                                    $t('clears at :time', { time: clearsAt })
                                }}</span
                            >
                        </span>
                    </Button>
                    <Button
                        variant="unstyled"
                        size="none"
                        type="button"
                        data-test="clear-status-menu-item"
                        :aria-label="$t('Clear status')"
                        class="flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-full bg-secondary text-muted-foreground hover:text-foreground"
                        @click="clearStatus()"
                    >
                        <X class="size-3" />
                    </Button>
                </div>
                <!-- The away toggle's leading glyph previews the state it would
                     switch *to*, so the row reads as an action rather than as a
                     second copy of the readout above. -->
                <Button
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="toggle-presence-menu-item"
                    :class="[rowClass, 'mt-0.5']"
                    @click="togglePresence()"
                >
                    <span class="flex w-3.75 justify-center">
                        <PresenceDot
                            :presence="togglesTo"
                            surface-class="bg-sidebar"
                            class="size-2.25"
                        />
                    </span>
                    <span class="min-w-0 flex-1 truncate">{{
                        togglesTo === 'away'
                            ? $t('Set yourself away')
                            : $t('Set yourself active')
                    }}</span>
                </Button>
                <Button
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="pause-notifications-menu-item"
                    :class="rowClass"
                    @click="presetsOpen = true"
                >
                    <Moon class="size-3.75 text-muted-foreground" />
                    <span class="min-w-0 flex-1 truncate">{{
                        $t('Pause notifications')
                    }}</span>
                    <ChevronRight class="size-3 text-muted-foreground/70" />
                </Button>
            </div>

            <!-- Appearance: the quick theme switcher only — the sidebar sits
                 behind a drawer below `md`, so its position row stays a
                 desktop affordance (and a Settings one). -->
            <div class="px-2 pt-2.5 pb-1">
                <div :class="sectionLabelClass">{{ $t('Appearance') }}</div>
                <div class="flex h-11 items-center gap-2.5 px-2.5">
                    <span class="flex-1 text-[13.5px] text-foreground">{{
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
            </div>

            <!-- Account + help. The keyboard-shortcuts row is dropped on
                 purpose: a phone has no hardware keyboard (design m8). -->
            <div class="px-2 pt-2.5 pb-2">
                <Link
                    :href="edit()"
                    data-test="settings-menu-item"
                    prefetch
                    :class="rowClass"
                    @click="close"
                >
                    <Settings class="size-3.75 text-muted-foreground" />
                    <span class="min-w-0 flex-1 truncate">{{
                        $t('Settings')
                    }}</span>
                </Link>
                <Button
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="replay-tour-menu-item"
                    :class="rowClass"
                    @click="replayTour"
                >
                    <Compass class="size-3.75 text-muted-foreground" />
                    <span class="min-w-0 flex-1 truncate">{{
                        $t('Replay tour')
                    }}</span>
                </Button>
            </div>

            <!-- Log out: a full-width outlined pill on its own tinted band,
                 deliberate and hard to fat-finger, then the version line. -->
            <div class="border-t border-border bg-muted/50 px-3 pt-2.5 pb-2.5">
                <Link
                    :href="logout()"
                    as="button"
                    data-test="logout-button"
                    class="flex h-11 w-full cursor-pointer items-center justify-center gap-2 rounded-full border border-border px-3 text-[13px] font-semibold text-destructive-text"
                    @click="handleLogout"
                >
                    <LogOut class="size-3.25" />
                    {{ $t('Log out') }}
                </Link>
            </div>
            <template v-if="status">
                <a
                    v-if="isBehind"
                    :href="status.notesUrl ?? '#'"
                    target="_blank"
                    rel="noopener noreferrer"
                    data-test="user-menu-version"
                    class="flex items-center justify-center gap-1.5 border-t border-border bg-muted/50 px-3 pt-1.5 font-mono text-[10.5px]"
                    :style="footerStyle"
                >
                    <span
                        aria-hidden="true"
                        class="size-1.5 rounded-full bg-brass"
                    />
                    <span class="text-muted-foreground"
                        >v{{ status.current }}</span
                    >
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
                    class="border-t border-border bg-muted/50 px-3 pt-1.5 text-center font-mono text-[10.5px] text-muted-foreground"
                    :style="footerStyle"
                >
                    {{ appName }} v{{ status.current }}
                </div>
            </template>

            <!-- The DND presets, as a second sheet over this one rather than a
                 nested flyout — a dropdown anchored inside a sheet is neither
                 reachable nor sizeable on a phone. -->
            <Dialog
                :open="presetsOpen"
                @update:open="(value) => (presetsOpen = value)"
            >
                <DialogContent
                    data-test="pause-notifications-submenu"
                    :show-close-button="false"
                    class="gap-0"
                >
                    <DialogTitle
                        class="px-1 pb-1 text-left text-[15px] font-semibold"
                    >
                        {{ $t('Pause notifications') }}
                    </DialogTitle>
                    <DialogDescription class="sr-only">
                        {{ $t('Choose how long to pause notifications.') }}
                    </DialogDescription>
                    <Button
                        v-for="preset in pausePresets"
                        :key="preset"
                        variant="unstyled"
                        size="none"
                        type="button"
                        :data-test="`pause-preset-${preset}`"
                        :class="rowClass"
                        @click="choosePreset(preset)"
                    >
                        {{ dndPauseLabel(preset) }}
                    </Button>
                    <Button
                        variant="unstyled"
                        size="none"
                        type="button"
                        data-test="pause-preset-custom"
                        :class="rowClass"
                        @click="openCustomPause"
                    >
                        {{ dndPauseLabel('custom') }}
                    </Button>
                    <div class="mx-1.5 my-1 h-px bg-border" />
                    <Link
                        :href="appearanceEdit()"
                        data-test="quiet-hours-menu-item"
                        :class="[
                            rowClass,
                            'justify-between text-muted-foreground',
                        ]"
                        @click="closeAll"
                    >
                        {{ $t('Quiet hours') }}
                        <span class="text-[11px] opacity-70">{{
                            $t('Settings')
                        }}</span>
                    </Link>
                </DialogContent>
            </Dialog>
        </DialogContent>
    </Dialog>
</template>
