<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    ChevronRight,
    Compass,
    Download,
    Keyboard,
    LayoutGrid,
    Monitor,
    Moon,
    PanelLeft,
    PanelRight,
    Settings,
    ShieldCheck,
    Sun,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import MenuSegmentedControl from '@/components/MenuSegmentedControl.vue';
import WorkspaceSheet from '@/components/navigation/WorkspaceSheet.vue';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/composables/useAppearance';
import { useAppInstall, useInstallRowBadge } from '@/composables/useAppInstall';
import { useDialog } from '@/composables/useDialog';
import { useOnboardingTour } from '@/composables/useOnboardingTour';
import { useSidebarPosition } from '@/composables/useSidebarPosition';
import { useTranslations } from '@/composables/useTranslations';
import {
    menuChevronClass,
    menuIconClass,
    menuRowClass,
    menuSectionClass,
    menuSectionLabelClass,
    menuSeparatorClass,
} from '@/lib/userMenu';
import type { UserMenuVariant } from '@/lib/userMenu';
import { edit } from '@/routes/profile';
import { edit as securityEdit } from '@/routes/security';
import { index as settingsIndex } from '@/routes/settings';
import type { Appearance, SidebarPosition } from '@/types';

/**
 * Everything below the STATUS group: the quick appearance switchers, the
 * install row, and the account and help rows — including the two the design
 * adds, "Switch workspace" (the same sheet the rail tile and both headers open)
 * and "Security & devices" (a deep link into the settings page that already
 * holds sessions, passkeys and two-factor).
 */
const props = defineProps<{
    variant: UserMenuVariant;
}>();

const emit = defineEmits<{
    /** A row traded the menu for a dialog or a page; the popover closes behind it. */
    dismiss: [];
    /** "Invite people" was chosen in the sheet "Switch workspace" opens. */
    /** "Join a workspace" was chosen in the sheet "Switch workspace" opens. */
}>();

const page = usePage();
const { t } = useTranslations();
const { open: openKeyboardShortcuts } = useDialog('shortcuts');
const { open: replayOnboardingTour } = useOnboardingTour();

// The quick theme and sidebar switchers reuse the same composables (and shared
// `sidebarPositions` prop) as Settings → Appearance, so flipping either here
// reflects there and back with no extra persistence.
const { appearance, updateAppearance } = useAppearance();
const { sidebarPosition, updateSidebarPosition } = useSidebarPosition();

const rowClass = computed(() => menuRowClass(props.variant));
const iconClass = computed(() => menuIconClass(props.variant));
const chevronClass = computed(() => menuChevronClass(props.variant));
const isPanel = computed(() => props.variant === 'panel');

/**
 * On the panel Settings opens on its full-screen index; in the popover it opens
 * straight on the profile pane beside the settings side nav.
 */
const settingsHref = computed(() => (isPanel.value ? settingsIndex() : edit()));

/** How many workspaces "Switch workspace" would offer, as the design's tally. */
const workspaceCount = computed(() => (page.props.teams ?? []).length);

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

// Install's permanent home: unlike the sidebar card it never nags and is never
// dismissed, so anyone who said "not now" can still find it. The NEW badge is
// spent by the first menu that carries the row.
const { showRow: showInstallRow } = useAppInstall();
const { open: openInstallDialog } = useDialog('install');
const installRowIsNew = useInstallRowBadge();

function openInstall(): void {
    openInstallDialog();
    emit('dismiss');
}

function openShortcuts(): void {
    openKeyboardShortcuts();
    emit('dismiss');
}

function replayTour(): void {
    replayOnboardingTour();
    emit('dismiss');
}

/** The switcher row's label column, so both controls line up at either density. */
const switcherRowClass = computed(() =>
    isPanel.value
        ? 'flex h-13 items-center gap-3 px-3.5 text-base text-foreground'
        : 'flex h-9 items-center gap-2.5 px-2.5 text-[13px] text-foreground',
);
</script>

<template>
    <div>
        <div :class="menuSeparatorClass(variant)" aria-hidden="true" />

        <!-- Appearance: label-left / segmented-control-right rows wired to the same
             composables as Settings → Appearance; selecting one applies instantly
             and leaves the menu open. -->
        <div :class="menuSectionClass(variant)">
            <div :class="menuSectionLabelClass(variant)">
                {{ $t('Appearance') }}
            </div>
            <div :class="switcherRowClass">
                <span class="min-w-0 flex-1 truncate">{{ $t('Theme') }}</span>
                <MenuSegmentedControl
                    :model-value="appearance"
                    :options="themeOptions"
                    :aria-label="$t('Theme')"
                    standalone
                    data-test="menu-theme-switcher"
                    @update:model-value="
                        (value) => updateAppearance(value as Appearance)
                    "
                />
            </div>
            <!-- The dock is an overlay drawer below `md`, not a positioned pane, so
                 its edge is a desktop affordance (and a Settings one). -->
            <div v-if="!isPanel" :class="switcherRowClass">
                <span class="min-w-0 flex-1 truncate">{{ $t('Sidebar') }}</span>
                <MenuSegmentedControl
                    :model-value="sidebarPosition"
                    :options="sidebarOptions"
                    :aria-label="$t('Sidebar position')"
                    standalone
                    data-test="menu-sidebar-switcher"
                    @update:model-value="
                        (value) =>
                            updateSidebarPosition(value as SidebarPosition)
                    "
                />
            </div>
        </div>

        <!-- Install: its own brass-tinted row, ahead of the account group. Absent
             once the app is installed, and on a browser that can neither prompt nor
             be talked through it. -->
        <div v-if="showInstallRow" :class="menuSectionClass(variant)">
            <Button
                variant="unstyled"
                size="none"
                type="button"
                data-test="install-app-menu-item"
                :class="[
                    rowClass,
                    'border border-brass-border/60 bg-brass/8 font-semibold',
                ]"
                @click="openInstall"
            >
                <Download :class="[iconClass, 'text-brass-fill-foreground']" />
                <span class="min-w-0 flex-1 truncate">{{
                    $t('Install app')
                }}</span>
                <span
                    v-if="installRowIsNew"
                    data-test="install-app-menu-badge"
                    class="inline-flex h-4.5 shrink-0 items-center rounded-full bg-primary px-1.75 text-[9px] font-bold tracking-[0.04em] text-primary-foreground"
                    >{{ $t('NEW') }}</span
                >
            </Button>
        </div>

        <div :class="menuSeparatorClass(variant)" aria-hidden="true" />

        <!-- Account -->
        <div :class="menuSectionClass(variant)">
            <div :class="menuSectionLabelClass(variant)">
                {{ $t('Account') }}
            </div>
            <Link
                :href="settingsHref"
                data-test="settings-menu-item"
                prefetch
                :class="rowClass"
                @click="emit('dismiss')"
            >
                <Settings :class="iconClass" />
                <span class="min-w-0 flex-1 truncate">{{
                    $t('Settings')
                }}</span>
            </Link>
            <!-- One workspace sheet, three anchors — this row is the fourth, and
                 opens the very same surface rather than a switcher of its own. -->
            <WorkspaceSheet :side="isPanel ? 'bottom' : 'right'">
                <Button
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="switch-workspace-menu-item"
                    :class="rowClass"
                >
                    <LayoutGrid :class="iconClass" />
                    <span class="min-w-0 flex-1 truncate">{{
                        $t('Switch workspace')
                    }}</span>
                    <span
                        v-if="workspaceCount > 0"
                        data-test="switch-workspace-count"
                        class="shrink-0 text-[11.5px] text-muted-foreground tabular-nums"
                        >{{ workspaceCount }}</span
                    >
                    <ChevronRight :class="chevronClass" />
                </Button>
            </WorkspaceSheet>
            <Link
                :href="securityEdit()"
                data-test="security-menu-item"
                prefetch
                :class="rowClass"
                @click="emit('dismiss')"
            >
                <ShieldCheck :class="iconClass" />
                <span class="min-w-0 flex-1 truncate">{{
                    $t('Security & devices')
                }}</span>
                <ChevronRight :class="chevronClass" />
            </Link>
        </div>

        <div :class="menuSeparatorClass(variant)" aria-hidden="true" />

        <!-- Help. The keyboard-shortcuts row is dropped on the panel on purpose: a
             phone has no hardware keyboard (design m8). -->
        <div :class="menuSectionClass(variant)">
            <div :class="menuSectionLabelClass(variant)">{{ $t('Help') }}</div>
            <Button
                v-if="!isPanel"
                variant="unstyled"
                size="none"
                type="button"
                data-test="keyboard-shortcuts-menu-item"
                :class="rowClass"
                @click="openShortcuts"
            >
                <Keyboard :class="iconClass" />
                <span class="min-w-0 flex-1 truncate">{{
                    $t('Keyboard shortcuts')
                }}</span>
                <span
                    class="inline-flex h-4.5 min-w-4 shrink-0 items-center justify-center rounded-[5px] border border-border px-1 font-mono text-[10px] font-semibold text-muted-foreground"
                    >?</span
                >
            </Button>
            <Button
                variant="unstyled"
                size="none"
                type="button"
                data-test="replay-tour-menu-item"
                :class="rowClass"
                @click="replayTour"
            >
                <Compass :class="iconClass" />
                <span class="min-w-0 flex-1 truncate">{{
                    $t('Replay tour')
                }}</span>
            </Button>
        </div>
    </div>
</template>
