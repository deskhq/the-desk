/**
 * The hard cap on a composer recording, in seconds. Deliberately a frontend
 * constant rather than a config/env setting: the server's real bound stays the
 * 25 MB attachment limit, and this only keeps a clip conversational.
 */
export const VOICE_MAX_DURATION_SECONDS = 300;

/** How long before the cap the recording timer shifts to its warning colour. */
export const VOICE_WARNING_SECONDS = 30;

/**
 * The filename prefix marking a clip recorded in the composer. Nothing at the
 * data layer distinguishes a voice message from a dropped audio file, so the
 * player branches its chrome on this prefix alone.
 */
export const VOICE_MESSAGE_PREFIX = 'voice-message-';

/** The extension used when a recorder reports a container we don't map. */
const FALLBACK_AUDIO_EXTENSION = 'webm';

/** Recorder MIME subtypes whose natural file extension differs from the subtype. */
const EXTENSION_BY_SUBTYPE: Record<string, string> = {
    mp4: 'm4a',
    mpeg: 'mp3',
    'x-wav': 'wav',
    'x-m4a': 'm4a',
};

/** Subtypes that already name their own extension. */
const PASSTHROUGH_SUBTYPES = ['webm', 'ogg', 'wav', 'flac', 'aac'];

/** Whether an attachment should render as an inline audio player. */
export function isAudioMime(mime: string): boolean {
    return mime.toLowerCase().startsWith('audio/');
}

/** The browser capabilities a composer recording needs, as far as we read them. */
export interface VoiceRecordingScope {
    MediaRecorder?: unknown;
    isSecureContext?: boolean;
    navigator?: { mediaDevices?: { getUserMedia?: unknown } };
}

/**
 * Whether this browser can record a voice message at all. `getUserMedia` is
 * gated on a secure context, and `MediaRecorder` is absent on older browsers —
 * where either is missing the composer omits the mic slot entirely rather than
 * offering a control that can only fail.
 */
export function isVoiceRecordingSupported(
    scope: VoiceRecordingScope = globalThis,
): boolean {
    return (
        typeof scope.MediaRecorder !== 'undefined' &&
        scope.isSecureContext === true &&
        typeof scope.navigator?.mediaDevices?.getUserMedia === 'function'
    );
}

/** Whether an attachment was recorded in the composer rather than uploaded. */
export function isVoiceMessageFilename(filename: string | null): boolean {
    return (filename ?? '').startsWith(VOICE_MESSAGE_PREFIX);
}

/**
 * The file extension for a recorder MIME type. `MediaRecorder` reports its own
 * container (`audio/webm` on Chrome and Firefox, `audio/mp4` on Safari), often
 * with a `;codecs=` suffix, so the clip is named after what was actually
 * recorded instead of a guessed extension.
 */
export function audioExtensionFor(mime: string): string {
    const subtype = mime.toLowerCase().split(';')[0].split('/')[1] ?? '';

    if (PASSTHROUGH_SUBTYPES.includes(subtype)) {
        return subtype;
    }

    return EXTENSION_BY_SUBTYPE[subtype] ?? FALLBACK_AUDIO_EXTENSION;
}

/** Name a recorded clip `voice-message-<unix seconds>.<ext>`. */
export function voiceMessageFilename(
    mime: string,
    timestampMs: number,
): string {
    const seconds = Math.floor(timestampMs / 1000);

    return `${VOICE_MESSAGE_PREFIX}${seconds}.${audioExtensionFor(mime)}`;
}

/**
 * Render a duration as a clock (`0:37`, `5:00`, `1:01:01`). An unresolved
 * duration — the `Infinity` a `MediaRecorder` blob reports before the seek
 * workaround lands, or a `NaN` from an unloaded element — reads as zero rather
 * than as garbage.
 */
export function formatClock(seconds: number): string {
    if (!Number.isFinite(seconds) || seconds < 0) {
        return '0:00';
    }

    const whole = Math.floor(seconds);
    const hours = Math.floor(whole / 3600);
    const minutes = Math.floor((whole % 3600) / 60);
    const rest = whole % 60;
    const pad = (part: number): string => String(part).padStart(2, '0');

    if (hours > 0) {
        return `${hours}:${pad(minutes)}:${pad(rest)}`;
    }

    return `${minutes}:${pad(rest)}`;
}

/**
 * Reduce decoded PCM samples to `bucketCount` waveform bars, each the loudest
 * absolute sample in its slice, normalised so the loudest bar is 1 — a quiet
 * clip then still draws a legible waveform rather than a flat line.
 */
export function waveformPeaks(
    samples: Float32Array,
    bucketCount: number,
): number[] {
    if (samples.length === 0 || bucketCount <= 0) {
        return [];
    }

    const width = samples.length / bucketCount;
    const peaks: number[] = [];
    let loudest = 0;

    for (let bucket = 0; bucket < bucketCount; bucket += 1) {
        const start = Math.floor(bucket * width);
        // A clip shorter than the bar count still gives every bar a sample.
        const end = Math.max(Math.floor((bucket + 1) * width), start + 1);
        let peak = 0;

        for (
            let index = start;
            index < end && index < samples.length;
            index += 1
        ) {
            peak = Math.max(peak, Math.abs(samples[index]));
        }

        loudest = Math.max(loudest, peak);
        peaks.push(peak);
    }

    if (loudest === 0) {
        return peaks;
    }

    return peaks.map((peak) => peak / loudest);
}

/**
 * Decoded peaks, keyed by source and bar count. The timeline is virtualized, so
 * a player remounts every time its message scrolls back into view; decoding
 * once per clip per session keeps that free. Nothing is persisted server-side —
 * a voice message carries no metadata row.
 */
const peakCache = new Map<string, number[]>();

/**
 * Decode a clip's waveform client-side. Any failure — an unsupported container,
 * a network hiccup, a browser without Web Audio — yields no peaks, and the
 * player falls back to a plain progress bar rather than breaking playback.
 */
export async function decodeWaveformPeaks(
    src: string,
    bucketCount: number,
): Promise<number[]> {
    const key = `${src}|${bucketCount}`;
    const cached = peakCache.get(key);

    if (cached) {
        return cached;
    }

    const AudioContextCtor =
        typeof AudioContext === 'undefined' ? undefined : AudioContext;

    if (!AudioContextCtor || typeof fetch !== 'function') {
        return [];
    }

    const context = new AudioContextCtor();

    try {
        const response = await fetch(src);
        const buffer = await context.decodeAudioData(
            await response.arrayBuffer(),
        );
        const peaks = waveformPeaks(buffer.getChannelData(0), bucketCount);

        if (peaks.length > 0) {
            peakCache.set(key, peaks);
        }

        return peaks;
    } catch {
        return [];
    } finally {
        void context.close();
    }
}
