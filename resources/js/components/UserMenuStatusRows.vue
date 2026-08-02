<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown, Moon, SmilePlus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import PresenceDot from '@/components/PresenceDot.vue';
import { Button } from '@/components/ui/button';
import UserStatusEmoji from '@/components/UserStatusEmoji.vue';
import { useDialog } from '@/composables/useDialog';
import { useUserMenu } from '@/composables/useUserMenu';
import { dndPauseLabel } from '@/lib/dndPause';
import type { DndPauseKey } from '@/lib/dndPause';
import {
    menuIconClass,
    menuRowClass,
    menuSectionClass,
    menuSectionLabelClass,
} from '@/lib/userMenu';
import type { UserMenuVariant } from '@/lib/userMenu';
import { edit as appearanceEdit } from '@/routes/appearance';
import type { User } from '@/types';

/**
 * The STATUS group: what the viewer's presence currently says, and every way to
 * change it. Shared verbatim by the desktop popover and the "You" panel — only
 * the row density differs.
 *
 * The pause presets open **inline** rather than as a flyout or a second sheet.
 * Both hosts are themselves floating layers, and a nested one would have to be
 * anchored differently on each; a disclosure under the row it belongs to is one
 * implementation that reads the same on a pointer and under a thumb.
 */
const props = defineProps<{
    user: User;
    variant: UserMenuVariant;
}>();

const emit = defineEmits<{
    /** A row traded the menu for a dialog; the popover host closes behind it. */
    dismiss: [];
}>();

const { open: openStatusDialog } = useDialog('status');
const { open: openDndPauseDialog } = useDialog('dnd');

const {
    ownStatus,
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
} = useUserMenu();

const rowClass = computed(() => menuRowClass(props.variant));
const iconClass = computed(() => menuIconClass(props.variant));
const isPanel = computed(() => props.variant === 'panel');

/** Whether the preset list is disclosed under the pause row. */
const presetsOpen = ref(false);

function openStatus(): void {
    openStatusDialog();
    emit('dismiss');
}

function choosePreset(key: DndPauseKey): void {
    choosePause(key);
    presetsOpen.value = false;
}

function openCustomPause(): void {
    openDndPauseDialog();
    presetsOpen.value = false;
    emit('dismiss');
}

/** The look of one preset row: a plain row, indented under its disclosure. */
const presetRowClass = computed(() =>
    isPanel.value
        ? 'flex h-11 w-full cursor-pointer items-center rounded-[11px] px-3.5 text-left text-[15px] text-foreground transition-colors hover:bg-muted/50'
        : 'flex h-8 w-full cursor-pointer items-center rounded-[9px] px-2.5 text-left text-[13px] text-foreground transition-colors hover:bg-secondary',
);
</script>

<template>
    <div :class="menuSectionClass(variant)">
        <div :class="menuSectionLabelClass(variant)">{{ $t('Status') }}</div>

        <!-- While in DND the group leads with the paused card: crescent, when it
             lifts in italic serif, and a one-tap pill — Resume for a manual
             pause, Snooze for quiet hours (which lifts tonight's window and lets
             the standing schedule resume on its own).

             The card sets two siblings against each other rather than a label
             against the row height, so ellipsising alone would not save it: with
             the pill unshrinkable the zero-basis label column was the only thing
             that could give, and the French snooze label starved the readout to
             nothing (#762). The row therefore wraps, and the label keeps a floor
             wide enough to stay worth reading. -->
        <div
            v-if="isDnd"
            data-test="dnd-paused-card"
            class="mb-1 flex flex-wrap items-center gap-2.5 rounded-[11px] border border-border bg-muted px-2.5 py-1.5"
            :class="isPanel ? 'min-h-12' : 'min-h-11'"
        >
            <Moon class="size-4 shrink-0 text-muted-foreground" />
            <span
                data-test="dnd-paused-label"
                class="flex min-w-20 flex-1 flex-col"
            >
                <span
                    class="truncate text-[13px] font-semibold text-foreground"
                    >{{ $t('Paused') }}</span
                >
                <span
                    v-if="pausedUntil"
                    data-test="dnd-paused-until"
                    class="truncate font-serif text-[11px] text-muted-foreground italic"
                    >{{ $t('until :time', { time: pausedUntil }) }}</span
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
                class="inline-flex h-7 min-w-0 cursor-pointer items-center rounded-full border border-border px-3 text-[11.5px] font-semibold text-muted-foreground hover:text-foreground"
                @click="resumeNotifications()"
            >
                <span class="truncate">{{ $t('Resume') }}</span>
            </Button>
            <Button
                v-else-if="quietHoursUntil"
                variant="unstyled"
                size="none"
                type="button"
                data-test="dnd-snooze-menu-item"
                class="inline-flex h-7 min-w-0 cursor-pointer items-center rounded-full border border-border px-3 text-[11.5px] font-semibold text-muted-foreground hover:text-foreground"
                @click="snoozeSchedule()"
            >
                <span class="truncate">{{ $t('Snooze schedule today') }}</span>
            </Button>
        </div>

        <!-- With nothing set the status is a plain row; once set it becomes a
             card showing the status and when it clears, with an inline ✕ that
             clears it outright. The card body reopens the dialog to edit. -->
        <Button
            v-if="!ownStatus"
            variant="unstyled"
            size="none"
            type="button"
            data-test="set-status-menu-item"
            :class="rowClass"
            @click="openStatus"
        >
            <SmilePlus :class="iconClass" />
            <span class="min-w-0 flex-1 truncate">{{
                $t('Set a status')
            }}</span>
        </Button>
        <!-- On the panel the card carries no vertical padding of its own: the
             editing half stretches to fill it, and it can only clear the 44px
             thumb target if the whole 52px row is its to take. -->
        <div
            v-else
            class="flex items-center gap-2.5 rounded-[11px] border border-border bg-muted px-2.5"
            :class="isPanel ? 'min-h-13' : 'min-h-11 py-1.5'"
        >
            <Button
                variant="unstyled"
                size="none"
                type="button"
                data-test="edit-status-menu-item"
                class="flex min-w-0 flex-1 cursor-pointer items-center gap-2.5 self-stretch text-left"
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
                        class="truncate font-serif text-[11px] text-muted-foreground italic"
                        >{{ $t('clears at :time', { time: clearsAt }) }}</span
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

        <!-- The away toggle's leading glyph previews the state it would switch
             *to*, so the row reads as an action rather than as a second copy of
             the readout in the header. -->
        <Button
            variant="unstyled"
            size="none"
            type="button"
            data-test="toggle-presence-menu-item"
            :class="[rowClass, 'mt-0.5']"
            @click="togglePresence()"
        >
            <span class="flex justify-center" :class="iconClass">
                <PresenceDot
                    :presence="togglesTo"
                    surface-class="bg-popover"
                    class="size-2.25 self-center"
                />
            </span>
            <span class="min-w-0 flex-1 truncate">{{
                togglesTo === 'away'
                    ? $t('Set yourself away')
                    : $t('Set yourself active')
            }}</span>
        </Button>

        <!-- Pause notifications, with its presets disclosed under the row.
             The label is a truncating flex child rather than a bare text node:
             a locale whose translation runs longer than the English (#760) would
             otherwise wrap out of the fixed row height and shove the chevron
             off-centre. Ellipsised text keeps the full string in the DOM, so the
             accessible name stays complete. -->
        <Button
            variant="unstyled"
            size="none"
            type="button"
            data-test="pause-notifications-menu-item"
            :aria-expanded="presetsOpen"
            :class="[rowClass, 'mt-0.5']"
            @click="presetsOpen = !presetsOpen"
        >
            <Moon :class="iconClass" />
            <span class="min-w-0 flex-1 truncate">{{
                $t('Pause notifications')
            }}</span>
            <ChevronDown
                class="size-3 shrink-0 text-muted-foreground transition-transform"
                :class="presetsOpen ? 'rotate-180' : ''"
            />
        </Button>
        <div
            v-if="presetsOpen"
            data-test="pause-notifications-submenu"
            class="mt-0.5 flex flex-col border-l border-border"
            :class="isPanel ? 'ml-4.5 pl-1.5' : 'ml-3.5 pl-1'"
        >
            <Button
                v-for="preset in pausePresets"
                :key="preset"
                variant="unstyled"
                size="none"
                type="button"
                :data-test="`pause-preset-${preset}`"
                :class="presetRowClass"
                @click="choosePreset(preset)"
            >
                <span class="min-w-0 flex-1 truncate">{{
                    dndPauseLabel(preset)
                }}</span>
            </Button>
            <Button
                variant="unstyled"
                size="none"
                type="button"
                data-test="pause-preset-custom"
                :class="presetRowClass"
                @click="openCustomPause"
            >
                <span class="min-w-0 flex-1 truncate">{{
                    dndPauseLabel('custom')
                }}</span>
            </Button>
            <Link
                :href="appearanceEdit()"
                data-test="quiet-hours-menu-item"
                :class="[presetRowClass, 'text-muted-foreground']"
                @click="emit('dismiss')"
            >
                <span class="min-w-0 flex-1 truncate">{{
                    $t('Quiet hours')
                }}</span>
                <span class="shrink-0 text-[11px] opacity-70">{{
                    $t('Settings')
                }}</span>
            </Link>
        </div>
    </div>
</template>
