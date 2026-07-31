<script setup lang="ts">
import {
    BarChart3,
    Camera,
    CalendarDays,
    Clock,
    File,
    Image,
    Mic,
} from '@lucide/vue';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import type { FormatAction } from '@/composables/useComposerFormat';
import type { QuickSchedulePreset } from '@/lib/scheduleTime';
import { quickSchedulePresets } from '@/lib/scheduleTime';

const props = defineProps<{
    /** The inline-format controls, each with its icon and accessible label. */
    formatActions: FormatAction[];
    /** Whether the composer knows its channel, which is what attachments need. */
    attachmentsEnabled: boolean;
    /** Whether this browser can record (MediaRecorder + a secure-context getUserMedia). */
    canRecord: boolean;
    /** Whether the Giphy picker is configured and usable on this composer. */
    gifAvailable: boolean;
    /** Whether the poll builder is enabled and usable on this composer. */
    pollAvailable: boolean;
    /** Whether this composer has a slash-command manifest to open the menu onto. */
    hasSlashCommands: boolean;
    /** Whether the composer body is empty, which is the only place a `/` is a command. */
    composerEmpty: boolean;
    /** Whether to offer the "schedule for later" rows (main channel composer only). */
    allowSchedule: boolean;
    /** Whether the body carries text to schedule (scheduling never carries attachments). */
    canSchedule: boolean;
    /** The viewer's stored IANA zone; drives the quick presets' resolved times. */
    timezone: string | null;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    /** Wrap the snapshotted selection in a Markdown marker. */
    format: [marker: string];
    /** Open the photo-library picker, which takes images and video. */
    photos: [];
    /** Open the device camera on a still photo. */
    camera: [];
    /** Open the unrestricted file picker. */
    file: [];
    /** Open the Giphy picker. */
    gif: [];
    /** Open the poll builder. */
    poll: [];
    /** Open the mic. */
    record: [];
    /** Seed the field with `/` and open the slash menu. */
    command: [];
    /** Schedule the body for the given UTC instant (a quick preset was chosen). */
    scheduleAt: [sendAt: string];
    /** Open the full schedule dialog to pick a custom time. */
    customTime: [];
}>();

/**
 * Resolved on open so the times stay current with the wall clock each time the
 * sheet is raised, rather than frozen at mount — the same contract the desktop
 * send-later menu keeps, off the same shared presets.
 */
const quickPresets = ref<QuickSchedulePreset[]>([]);

watch(open, (isOpen) => {
    if (isOpen) {
        quickPresets.value = quickSchedulePresets(props.timezone);
    }
});

/**
 * Every tile leaves the sheet behind: run its action, then dismiss. The format
 * strip is the exception — formatting is repetitive, so it stays open across
 * taps. The action runs before the dismissal so a tile that proxies to a native
 * picker still does so inside the tap's user gesture, which Safari requires.
 */
function act(run: () => void): void {
    run();
    open.value = false;
}

/** The shared look of one 76pt tile (design 1c). */
const tileClass =
    'flex h-19 flex-col items-center justify-center gap-2 rounded-2xl bg-muted px-0.5 text-[11.5px] leading-tight font-medium text-foreground transition-colors hover:bg-accent active:bg-accent disabled:opacity-55';

/** The shared look of one 52pt schedule row. */
const rowClass =
    'flex h-13 w-full items-center gap-3.5 rounded-[11px] px-3 text-left text-[15px] text-foreground transition-colors hover:bg-muted/50 active:bg-muted';
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            data-test="composer-attach-sheet"
            :show-close-button="false"
            class="gap-0 px-3.5"
        >
            <DialogTitle class="sr-only">{{
                $t('Compose options')
            }}</DialogTitle>
            <DialogDescription class="sr-only">
                {{ $t('Add to your message, or format it.') }}
            </DialogDescription>

            <!-- The format strip. A tile per mark would have to be re-entered
                 for each one, so formatting gets a row of its own and the sheet
                 stays open across taps. -->
            <div
                class="flex items-center gap-1.5 pt-1 pb-3"
                data-test="composer-format-cluster"
            >
                <Button
                    v-for="action in formatActions"
                    :key="action.marker"
                    variant="ghost"
                    size="icon"
                    :data-test="`message-composer-format-${action.marker}`"
                    class="size-11 shrink-0 rounded-full text-muted-foreground"
                    :aria-label="action.label"
                    @click="emit('format', action.marker)"
                >
                    <component :is="action.icon" class="size-4.5" />
                </Button>
            </div>

            <div
                v-if="
                    attachmentsEnabled ||
                    gifAvailable ||
                    pollAvailable ||
                    (hasSlashCommands && composerEmpty)
                "
                class="grid grid-cols-4 gap-2.5 border-t border-border pt-3.5"
            >
                <Button
                    v-if="attachmentsEnabled"
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="composer-attach-tile"
                    data-tile="photos"
                    :class="tileClass"
                    @click="act(() => emit('photos'))"
                >
                    <Image class="size-5.5 text-brass-fill-foreground" />
                    {{ $t('Photos') }}
                </Button>

                <!-- Hands off to the OS camera through a `capture` input, so
                     the tile is worth offering only where one answers it —
                     which is this sheet, and this sheet is below `md` only. -->
                <Button
                    v-if="attachmentsEnabled"
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="composer-attach-tile"
                    data-tile="camera"
                    :class="tileClass"
                    :aria-label="$t('Camera')"
                    @click="act(() => emit('camera'))"
                >
                    <Camera class="size-5.5 text-brass-fill-foreground" />
                    {{ $t('Camera') }}
                </Button>

                <Button
                    v-if="attachmentsEnabled"
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="composer-attach-tile"
                    data-tile="file"
                    :class="tileClass"
                    @click="act(() => emit('file'))"
                >
                    <File class="size-5.5 text-brass-fill-foreground" />
                    {{ $t('File') }}
                </Button>

                <Button
                    v-if="gifAvailable"
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="composer-attach-tile"
                    data-tile="gif"
                    :class="tileClass"
                    @click="act(() => emit('gif'))"
                >
                    <span
                        aria-hidden="true"
                        class="font-serif text-[17px] leading-[22px] font-semibold text-brass-fill-foreground"
                        >GIF</span
                    >
                    {{ $t('GIF') }}
                </Button>

                <Button
                    v-if="pollAvailable"
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="composer-attach-tile"
                    data-tile="poll"
                    :class="tileClass"
                    @click="act(() => emit('poll'))"
                >
                    <BarChart3 class="size-5.5 text-brass-fill-foreground" />
                    {{ $t('Poll') }}
                </Button>

                <Button
                    v-if="canRecord"
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="composer-attach-tile"
                    data-tile="voice"
                    :class="tileClass"
                    @click="act(() => emit('record'))"
                >
                    <Mic class="size-5.5 text-brass-fill-foreground" />
                    {{ $t('Voice') }}
                </Button>

                <!-- A `/` only opens a command at position 0, so the tile is
                     offered only while there is nothing to push it off it. -->
                <Button
                    v-if="hasSlashCommands && composerEmpty"
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="composer-attach-tile"
                    data-tile="command"
                    :class="tileClass"
                    @click="act(() => emit('command'))"
                >
                    <span
                        aria-hidden="true"
                        class="font-mono text-[19px] leading-[22px] font-medium text-brass-fill-foreground"
                        >/</span
                    >
                    {{ $t('Command') }}
                </Button>
            </div>

            <div
                v-if="allowSchedule && canSchedule"
                class="mt-3.5 flex flex-col border-t border-border pt-2"
                data-test="composer-sheet-schedule"
            >
                <Button
                    v-for="preset in quickPresets"
                    :key="preset.key"
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="sheet-send-later-preset"
                    :data-preset="preset.key"
                    :class="rowClass"
                    @click="act(() => emit('scheduleAt', preset.sendAt))"
                >
                    <Clock class="size-5 text-muted-foreground" />
                    <span class="flex-1">{{ $t(preset.label) }}</span>
                    <span class="text-[13px] text-muted-foreground">{{
                        preset.preview
                    }}</span>
                </Button>

                <Button
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="sheet-send-later-custom"
                    :class="rowClass"
                    @click="act(() => emit('customTime'))"
                >
                    <CalendarDays class="size-5 text-muted-foreground" />
                    <span class="flex-1">{{ $t('Custom time…') }}</span>
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
