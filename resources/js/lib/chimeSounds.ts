import type { ChimeSound } from '@/types';

/**
 * A single synthesized voice: an oscillator at `frequency` that fades in and back
 * out over `duration`, starting `startAt` seconds after playback begins.
 */
type ChimeVoice = {
    type: OscillatorType;
    frequency: number;
    startAt: number;
    duration: number;
    gain: number;
};

/**
 * Chimes are synthesized on the fly with the Web Audio API rather than shipped as
 * binary audio, so previews and playback need no bundled assets or network fetch.
 * Each audible sound maps to a short recipe of one or more voices.
 */
const RECIPES: Record<Exclude<ChimeSound, 'off'>, ChimeVoice[]> = {
    ping: [
        {
            type: 'sine',
            frequency: 880,
            startAt: 0,
            duration: 0.16,
            gain: 0.18,
        },
        {
            type: 'sine',
            frequency: 1320,
            startAt: 0.09,
            duration: 0.22,
            gain: 0.16,
        },
    ],
    chime: [
        { type: 'sine', frequency: 660, startAt: 0, duration: 0.5, gain: 0.16 },
        { type: 'sine', frequency: 990, startAt: 0, duration: 0.5, gain: 0.1 },
        {
            type: 'sine',
            frequency: 1320,
            startAt: 0.02,
            duration: 0.45,
            gain: 0.07,
        },
    ],
    knock: [
        {
            type: 'triangle',
            frequency: 180,
            startAt: 0,
            duration: 0.13,
            gain: 0.3,
        },
        {
            type: 'triangle',
            frequency: 150,
            startAt: 0.13,
            duration: 0.14,
            gain: 0.26,
        },
    ],
    pop: [
        {
            type: 'square',
            frequency: 520,
            startAt: 0,
            duration: 0.08,
            gain: 0.12,
        },
    ],
};

let sharedContext: AudioContext | null = null;

/**
 * Lazily create the single shared AudioContext, or return null where Web Audio is
 * unavailable (SSR, or a browser without support).
 */
function audioContext(): AudioContext | null {
    if (
        typeof window === 'undefined' ||
        typeof window.AudioContext === 'undefined'
    ) {
        return null;
    }

    if (sharedContext === null) {
        sharedContext = new AudioContext();
    }

    return sharedContext;
}

/**
 * Resume the shared AudioContext. Browsers start it "suspended" until a user
 * gesture, so this must be called from within a real interaction to unlock later
 * programmatic playback (the browser autoplay policy).
 */
export function unlockChimeAudio(): void {
    const context = audioContext();

    if (context !== null && context.state === 'suspended') {
        void context.resume();
    }
}

/**
 * Play a chime by its preference value. "off" (or any environment without Web
 * Audio) is a no-op.
 */
export function playChime(sound: ChimeSound): void {
    if (sound === 'off') {
        return;
    }

    const context = audioContext();

    if (context === null) {
        return;
    }

    if (context.state === 'suspended') {
        void context.resume();
    }

    const now = context.currentTime;

    for (const voice of RECIPES[sound]) {
        const oscillator = context.createOscillator();
        const amplifier = context.createGain();

        oscillator.type = voice.type;
        oscillator.frequency.value = voice.frequency;

        const startTime = now + voice.startAt;
        const stopTime = startTime + voice.duration;

        // A quick attack then an exponential decay to a near-zero floor keeps each
        // voice soft and click-free (exponential ramps cannot reach a true zero).
        amplifier.gain.setValueAtTime(0.0001, startTime);
        amplifier.gain.exponentialRampToValueAtTime(
            voice.gain,
            startTime + 0.01,
        );
        amplifier.gain.exponentialRampToValueAtTime(0.0001, stopTime);

        oscillator.connect(amplifier).connect(context.destination);
        oscillator.start(startTime);
        oscillator.stop(stopTime);
    }
}
