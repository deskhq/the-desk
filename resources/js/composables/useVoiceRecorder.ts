import { computed, onScopeDispose, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { toast } from 'vue-sonner';
import { useTranslations } from '@/composables/useTranslations';
import {
    VOICE_MAX_DURATION_SECONDS,
    VOICE_WARNING_SECONDS,
    voiceMessageFilename,
} from '@/lib/audio';

/** How often the elapsed timer and the input-level meter refresh, in ms. */
const TICK_MS = 100;

/**
 * The slice of `MediaRecorder` this composable drives. Narrowing it to what we
 * actually use keeps the recording lifecycle unit-testable with a hand-driven
 * stand-in, no browser required.
 */
export interface VoiceRecording {
    /** The container the recorder actually produced, which names the clip. */
    mimeType: string;
    start: () => void;
    stop: () => void;
    ondataavailable: ((event: { data: Blob }) => void) | null;
    onstop: (() => void) | null;
    onerror: (() => void) | null;
}

/**
 * A live read of the microphone's input level, driving the recording strip's
 * meter. Ephemeral by design — nothing here is persisted with the clip.
 */
export interface VoiceLevelMeter {
    /** The current level, 0–1. */
    read: () => number;
    /** Release the audio graph. */
    stop: () => void;
}

export interface VoiceRecorderOptions {
    /** Receives the finished clip, ready to stage in the composer tray. */
    onRecorded: (file: File) => void;
    /** The hard cap; defaults to {@see VOICE_MAX_DURATION_SECONDS}. */
    maxDurationSeconds?: number;
    /** Open the microphone; injectable for tests. */
    requestStream?: () => Promise<MediaStream>;
    /** Build the recorder over an open stream; injectable for tests. */
    createRecording?: (stream: MediaStream) => VoiceRecording;
    /** Build the input-level meter, or null where Web Audio is unavailable. */
    createLevelMeter?: (stream: MediaStream) => VoiceLevelMeter | null;
    /** The clock, so tests can drive elapsed time deterministically. */
    now?: () => number;
}

export interface VoiceRecorder {
    /** Whether a recording is in progress (the composer shows its strip). */
    isRecording: Ref<boolean>;
    /** How long the current recording has run, in seconds. */
    elapsedSeconds: Ref<number>;
    /** The live input level, 0–1; zero when no meter is available. */
    level: Ref<number>;
    /** Whether the cap is within the final warning window. */
    isNearingLimit: ComputedRef<boolean>;
    /** Open the mic and begin recording; a no-op while already recording. */
    start: () => Promise<void>;
    /** Finish and stage the clip. */
    stop: () => void;
    /** Abandon the recording, keeping nothing. */
    cancel: () => void;
}

/** Open the microphone through the real browser API. */
function defaultRequestStream(): Promise<MediaStream> {
    return navigator.mediaDevices.getUserMedia({ audio: true });
}

/** Wrap a stream in the browser's own `MediaRecorder`. */
function defaultCreateRecording(stream: MediaStream): VoiceRecording {
    return new MediaRecorder(stream) as unknown as VoiceRecording;
}

/**
 * Meter the microphone through a Web Audio `AnalyserNode`, reading the RMS of
 * the live time-domain data. Returns null where Web Audio is missing — the
 * recording itself still works, the strip just draws a flat meter.
 */
function defaultCreateLevelMeter(stream: MediaStream): VoiceLevelMeter | null {
    const AudioContextCtor =
        typeof AudioContext === 'undefined' ? undefined : AudioContext;

    if (!AudioContextCtor) {
        return null;
    }

    const context = new AudioContextCtor();
    const source = context.createMediaStreamSource(stream);
    const analyser = context.createAnalyser();
    analyser.fftSize = 512;
    source.connect(analyser);

    const samples = new Uint8Array(analyser.fftSize);

    return {
        read: () => {
            analyser.getByteTimeDomainData(samples);

            let sum = 0;

            for (const sample of samples) {
                // Byte time-domain data is centred on 128; deviation is signal.
                const deviation = (sample - 128) / 128;
                sum += deviation * deviation;
            }

            // RMS runs low for speech, so scale it into a visible band.
            return Math.min(Math.sqrt(sum / samples.length) * 3, 1);
        },
        stop: () => {
            source.disconnect();
            analyser.disconnect();
            void context.close();
        },
    };
}

/**
 * Own one composer recording end to end: open the mic, drive a live elapsed
 * timer and input-level meter, cap the clip at five minutes, and hand back a
 * named `File` that the ordinary attachment tray uploads like any other file.
 * Nothing here is voice-specific at the data layer — the clip is a plain audio
 * attachment. Every browser seam is injectable, so the lifecycle unit-tests
 * headlessly.
 */
export function useVoiceRecorder(options: VoiceRecorderOptions): VoiceRecorder {
    const { t } = useTranslations();
    const requestStream = options.requestStream ?? defaultRequestStream;
    const createRecording = options.createRecording ?? defaultCreateRecording;
    const createLevelMeter =
        options.createLevelMeter ?? defaultCreateLevelMeter;
    const now = options.now ?? (() => Date.now());
    const maxSeconds = options.maxDurationSeconds ?? VOICE_MAX_DURATION_SECONDS;

    const isRecording = ref(false);
    const elapsedSeconds = ref(0);
    const level = ref(0);

    const isNearingLimit = computed(
        () =>
            isRecording.value &&
            maxSeconds - elapsedSeconds.value <= VOICE_WARNING_SECONDS,
    );

    // Held outside the reactive refs: a MediaStream and its audio graph are not
    // data to proxy, and the chunk list churns on every `dataavailable`.
    let stream: MediaStream | null = null;
    let recording: VoiceRecording | null = null;
    let meter: VoiceLevelMeter | null = null;
    let ticker: ReturnType<typeof setInterval> | null = null;
    let chunks: Blob[] = [];
    let keepClip = false;
    let startedAt = 0;

    /** Close the mic and the audio graph, leaving the composable idle. */
    function teardown(): void {
        if (ticker !== null) {
            clearInterval(ticker);
            ticker = null;
        }

        meter?.stop();
        meter = null;

        for (const track of stream?.getTracks() ?? []) {
            track.stop();
        }

        stream = null;
        recording = null;
        isRecording.value = false;
        level.value = 0;
    }

    /** Assemble the captured chunks into the staged clip. */
    function finish(mimeType: string): void {
        const captured = chunks;
        chunks = [];
        teardown();

        if (!keepClip || captured.length === 0) {
            return;
        }

        const blob = new Blob(captured, { type: mimeType });

        options.onRecorded(
            new File([blob], voiceMessageFilename(mimeType, now()), {
                type: mimeType,
            }),
        );
    }

    function tick(): void {
        elapsedSeconds.value = (now() - startedAt) / 1000;
        level.value = meter?.read() ?? 0;

        if (elapsedSeconds.value >= maxSeconds) {
            stop();
        }
    }

    /** Wind the recorder down, keeping or discarding what it captured. */
    function settle(keep: boolean): void {
        if (!isRecording.value || !recording) {
            return;
        }

        keepClip = keep;

        // The recorder settles through `onstop`; if the browser never fires it
        // (or already has), the teardown below still leaves us idle.
        recording.stop();
    }

    async function start(): Promise<void> {
        if (isRecording.value) {
            return;
        }

        let opened: MediaStream;

        try {
            opened = await requestStream();
        } catch {
            // Denied permission, no input device, or a hardware failure — all
            // read the same to the user, and nothing is staged either way.
            toast.error(t('Microphone unavailable'), {
                description: t(
                    'Allow microphone access in your browser to record a voice message.',
                ),
            });

            return;
        }

        stream = opened;
        chunks = [];
        keepClip = false;
        startedAt = now();
        elapsedSeconds.value = 0;
        level.value = 0;

        const active = createRecording(opened);
        recording = active;
        meter = createLevelMeter(opened);

        active.ondataavailable = (event) => {
            if (event.data.size > 0) {
                chunks.push(event.data);
            }
        };
        active.onstop = () => finish(active.mimeType);
        active.onerror = () => {
            keepClip = false;
            chunks = [];
            teardown();
            toast.error(t('Microphone unavailable'), {
                description: t(
                    'Allow microphone access in your browser to record a voice message.',
                ),
            });
        };

        isRecording.value = true;
        active.start();
        ticker = setInterval(tick, TICK_MS);
    }

    function stop(): void {
        settle(true);
    }

    function cancel(): void {
        settle(false);
    }

    onScopeDispose(() => {
        // A recording still running when the composer goes away keeps the mic
        // light on; abandon it rather than staging a clip nobody asked for.
        cancel();
        teardown();
    });

    return {
        isRecording,
        elapsedSeconds,
        level,
        isNearingLimit,
        start,
        stop,
        cancel,
    };
}
