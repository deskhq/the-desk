<script setup lang="ts">
import { Pause, Play } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/composables/useTranslations';
import {
    decodeWaveformPeaks,
    formatClock,
    isVoiceMessageFilename,
} from '@/lib/audio';

const props = withDefaults(
    defineProps<{
        /** The authorized media URL, or a local blob URL for a tray preview. */
        src: string;
        /**
         * The clip's filename, shown above the scrubber. A clip recorded in
         * the composer is recognised by its `voice-message-` prefix and drops
         * the line entirely — its generated name would be noise. Nothing at the
         * data layer distinguishes the two, so the filename is the only signal.
         */
        filename?: string | null;
        /** The denser tray-preview silhouette, rather than the timeline card. */
        compact?: boolean;
        /**
         * How many waveform bars to decode the clip into. Defaults to the
         * card's width; overridable so a narrower surface draws fewer.
         */
        bars?: number;
    }>(),
    { filename: null, compact: false, bars: undefined },
);

const { t } = useTranslations();

const audio = ref<HTMLAudioElement | null>(null);
const peaks = ref<number[]>([]);
const duration = ref(0);
const currentTime = ref(0);
const isPlaying = ref(false);

/**
 * True while the `MediaRecorder` duration workaround is in flight: a clip
 * recorded in the browser reports `Infinity` until it has been seeked past its
 * own end, so playback time is withheld until the real length lands.
 */
let resolvingDuration = false;

/** A recorded clip shows no filename line; a dropped audio file keeps its own. */
const shownFilename = computed(() =>
    isVoiceMessageFilename(props.filename ?? null) ? null : props.filename,
);

const barCount = computed(() => props.bars ?? (props.compact ? 32 : 56));
const progress = computed(() =>
    duration.value > 0 ? Math.min(currentTime.value / duration.value, 1) : 0,
);

/** How far into the clip the scrubber sits, as a percentage for the fill. */
const progressPercent = computed(() => `${progress.value * 100}%`);

const seekLabel = computed(() =>
    t(':elapsed of :total', {
        elapsed: formatClock(currentTime.value),
        total: formatClock(duration.value),
    }),
);

onMounted(async () => {
    peaks.value = await decodeWaveformPeaks(props.src, barCount.value);
});

/**
 * A `MediaRecorder` blob carries no duration in its container, so the element
 * reports `Infinity` until it is seeked past the end — the standard workaround,
 * which then rewinds on the first `timeupdate` that reports a real length.
 */
function onLoadedMetadata(): void {
    const element = audio.value;

    if (!element) {
        return;
    }

    if (Number.isFinite(element.duration)) {
        duration.value = element.duration;

        return;
    }

    resolvingDuration = true;
    element.currentTime = 1e101;
}

function onTimeUpdate(): void {
    const element = audio.value;

    if (!element) {
        return;
    }

    if (resolvingDuration) {
        if (!Number.isFinite(element.duration)) {
            return;
        }

        resolvingDuration = false;
        duration.value = element.duration;
        element.currentTime = 0;
        currentTime.value = 0;

        return;
    }

    currentTime.value = element.currentTime;
}

function onEnded(): void {
    const element = audio.value;

    if (element) {
        element.currentTime = 0;
    }

    currentTime.value = 0;
    isPlaying.value = false;
}

function toggle(): void {
    const element = audio.value;

    if (!element) {
        return;
    }

    if (isPlaying.value) {
        element.pause();

        return;
    }

    void element.play();
}

/** Move the playhead to a fraction of the clip, clamped to its bounds. */
function seekToRatio(ratio: number): void {
    const element = audio.value;

    if (!element || duration.value <= 0) {
        return;
    }

    const seconds = Math.min(Math.max(ratio, 0), 1) * duration.value;
    element.currentTime = seconds;
    currentTime.value = seconds;
}

function seekBy(seconds: number): void {
    seekToRatio((currentTime.value + seconds) / duration.value);
}

function onScrub(event: MouseEvent): void {
    const track = event.currentTarget as HTMLElement;
    const box = track.getBoundingClientRect();

    if (box.width <= 0) {
        return;
    }

    seekToRatio((event.clientX - box.left) / box.width);
}

const SEEK_STEP_SECONDS = 5;

function onScrubKeydown(event: KeyboardEvent): void {
    const steps: Record<string, () => void> = {
        ArrowRight: () => seekBy(SEEK_STEP_SECONDS),
        ArrowUp: () => seekBy(SEEK_STEP_SECONDS),
        ArrowLeft: () => seekBy(-SEEK_STEP_SECONDS),
        ArrowDown: () => seekBy(-SEEK_STEP_SECONDS),
        Home: () => seekToRatio(0),
        End: () => seekToRatio(1),
    };

    const step = steps[event.key];

    if (!step) {
        return;
    }

    event.preventDefault();
    step();
}

/** Whether a waveform bar sits in the played portion of the clip. */
function isPlayed(index: number): boolean {
    return index / barCount.value < progress.value;
}
</script>

<template>
    <div
        data-test="audio-player"
        class="flex items-center gap-3 rounded-2xl border border-border bg-card"
        :class="compact ? 'h-19 w-70 px-3' : 'w-85 max-w-full px-3.5 py-2.5'"
    >
        <!-- A user-recorded clip or an uploaded audio file has no caption track
             to offer, and none can be synthesised here: transcription is an
             explicit non-goal of #525. The element is chrome-less anyway — the
             labelled controls below are the accessible surface. -->
        <!-- eslint-disable-next-line vuejs-accessibility/media-has-caption -->
        <audio
            ref="audio"
            :src="src"
            preload="metadata"
            class="hidden"
            @loadedmetadata="onLoadedMetadata"
            @timeupdate="onTimeUpdate"
            @play="isPlaying = true"
            @pause="isPlaying = false"
            @ended="onEnded"
        ></audio>

        <Button
            type="button"
            size="none"
            variant="unstyled"
            data-test="audio-player-toggle"
            :aria-label="isPlaying ? $t('Pause') : $t('Play')"
            class="flex shrink-0 items-center justify-center bg-primary text-brass hover:bg-primary/90"
            :class="compact ? 'size-9.5 rounded-[10px]' : 'size-9 rounded-full'"
            @click="toggle"
        >
            <component :is="isPlaying ? Pause : Play" class="size-3.5" />
        </Button>

        <div class="flex min-w-0 flex-1 flex-col gap-1.5">
            <span
                v-if="shownFilename"
                data-test="audio-player-filename"
                class="truncate text-[12.5px] font-semibold text-foreground"
            >
                {{ shownFilename }}
            </span>

            <div
                data-test="audio-player-scrubber"
                role="slider"
                tabindex="0"
                :aria-label="$t('Seek')"
                aria-valuemin="0"
                :aria-valuemax="Math.round(duration)"
                :aria-valuenow="Math.round(currentTime)"
                :aria-valuetext="seekLabel"
                class="cursor-pointer rounded focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                @click="onScrub"
                @keydown="onScrubKeydown"
            >
                <!-- Decoded waveform: the played bars carry the brass fill. -->
                <div
                    v-if="peaks.length > 0"
                    class="flex items-center gap-px"
                    :class="compact ? 'h-4' : 'h-6'"
                >
                    <span
                        v-for="(peak, index) in peaks"
                        :key="index"
                        data-test="audio-player-bar"
                        :data-played="isPlayed(index) ? 'true' : 'false'"
                        class="min-h-0.5 flex-1 rounded-full"
                        :class="
                            isPlayed(index) ? 'bg-brass-border' : 'bg-border'
                        "
                        :style="{ height: `${Math.max(peak * 100, 8)}%` }"
                    ></span>
                </div>
                <!-- Undecodable clip (or one still decoding): the plain bar. -->
                <div
                    v-else
                    data-test="audio-player-track"
                    class="relative h-1.25 rounded-full bg-border"
                >
                    <span
                        class="absolute inset-y-0 left-0 rounded-full bg-brass-border"
                        :style="{ width: progressPercent }"
                    ></span>
                </div>
            </div>

            <div
                class="flex items-baseline justify-between text-[11px] text-muted-foreground tabular-nums"
            >
                <span
                    data-test="audio-player-elapsed"
                    :class="
                        isPlaying
                            ? 'font-semibold text-brass-fill-foreground'
                            : ''
                    "
                >
                    {{ formatClock(currentTime) }}
                </span>
                <span data-test="audio-player-duration">
                    {{ formatClock(duration) }}
                </span>
            </div>
        </div>
    </div>
</template>
