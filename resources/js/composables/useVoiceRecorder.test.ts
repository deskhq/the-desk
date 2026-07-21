import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import type { EffectScope } from 'vue';

const { toastError } = vi.hoisted(() => ({ toastError: vi.fn() }));

vi.mock('vue-sonner', () => ({
    toast: { error: toastError, success: vi.fn() },
}));

import { useVoiceRecorder } from '@/composables/useVoiceRecorder';
import type {
    VoiceLevelMeter,
    VoiceRecorder,
    VoiceRecording,
} from '@/composables/useVoiceRecorder';

/** A hand-driven stand-in for the browser's `MediaRecorder`. */
class FakeRecording implements VoiceRecording {
    ondataavailable: ((event: { data: Blob }) => void) | null = null;
    onstop: (() => void) | null = null;
    onerror: (() => void) | null = null;
    started = false;
    stopped = false;

    constructor(public mimeType = 'audio/webm;codecs=opus') {}

    start(): void {
        this.started = true;
    }

    /** Emit a chunk and settle, the way a real recorder does on `stop()`. */
    stop(): void {
        this.stopped = true;
        this.ondataavailable?.({ data: new Blob(['clip-bytes']) });
        this.onstop?.();
    }
}

/** A stream whose track stops we can assert on (the mic light must go out). */
function fakeStream(): { stream: MediaStream; stopped: () => number } {
    let stops = 0;
    const track = { stop: () => (stops += 1) };
    const stream = {
        getTracks: () => [track],
    } as unknown as MediaStream;

    return { stream, stopped: () => stops };
}

interface Harness {
    recorder: VoiceRecorder;
    recording: FakeRecording;
    recorded: File[];
    meterStops: () => number;
    trackStops: () => number;
    scope: EffectScope;
}

function mount(
    options: {
        requestStream?: () => Promise<MediaStream>;
        level?: number;
        mimeType?: string;
    } = {},
): Harness {
    const recording = new FakeRecording(options.mimeType);
    const recorded: File[] = [];
    const { stream, stopped: trackStops } = fakeStream();
    let meterStops = 0;

    const meter: VoiceLevelMeter = {
        read: () => options.level ?? 0.5,
        stop: () => (meterStops += 1),
    };

    const scope = effectScope();
    const recorder = scope.run(() =>
        useVoiceRecorder({
            onRecorded: (file) => recorded.push(file),
            requestStream:
                options.requestStream ?? (() => Promise.resolve(stream)),
            createRecording: () => recording,
            createLevelMeter: () => meter,
            now: () => Date.now(),
        }),
    ) as VoiceRecorder;

    return {
        recorder,
        recording,
        recorded,
        meterStops: () => meterStops,
        trackStops,
        scope,
    };
}

describe('useVoiceRecorder', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2024-07-18T14:04:35.412Z'));
        toastError.mockClear();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('starts idle', () => {
        const { recorder, scope } = mount();

        expect(recorder.isRecording.value).toBe(false);
        expect(recorder.elapsedSeconds.value).toBe(0);
        scope.stop();
    });

    it('opens the mic and starts the recorder', async () => {
        const { recorder, recording, scope } = mount();

        await recorder.start();

        expect(recorder.isRecording.value).toBe(true);
        expect(recording.started).toBe(true);
        scope.stop();
    });

    it('ignores a second start while already recording', async () => {
        const requestStream = vi.fn(() => Promise.resolve(fakeStream().stream));
        const { recorder, scope } = mount({ requestStream });

        await recorder.start();
        await recorder.start();

        expect(requestStream).toHaveBeenCalledTimes(1);
        scope.stop();
    });

    it('ticks the elapsed timer and the input level while recording', async () => {
        const { recorder, scope } = mount({ level: 0.75 });

        await recorder.start();
        await vi.advanceTimersByTimeAsync(2_500);

        expect(recorder.elapsedSeconds.value).toBe(2.5);
        expect(recorder.level.value).toBe(0.75);
        scope.stop();
    });

    it('warns only over the final thirty seconds', async () => {
        const { recorder, scope } = mount();

        await recorder.start();
        await vi.advanceTimersByTimeAsync(269_000);
        expect(recorder.isNearingLimit.value).toBe(false);

        await vi.advanceTimersByTimeAsync(2_000);
        expect(recorder.isNearingLimit.value).toBe(true);
        scope.stop();
    });

    it('stages the clip as a named file when stopped', async () => {
        const { recorder, recorded, scope } = mount();

        await recorder.start();
        recorder.stop();
        await vi.advanceTimersByTimeAsync(0);

        expect(recorded).toHaveLength(1);
        expect(recorded[0].name).toBe('voice-message-1721311475.webm');
        expect(recorded[0].type).toBe('audio/webm;codecs=opus');
        expect(recorder.isRecording.value).toBe(false);
        scope.stop();
    });

    it('names a Safari clip from the container the recorder actually produced', async () => {
        const { recorder, recorded, scope } = mount({ mimeType: 'audio/mp4' });

        await recorder.start();
        recorder.stop();
        await vi.advanceTimersByTimeAsync(0);

        expect(recorded[0].name).toBe('voice-message-1721311475.m4a');
        scope.stop();
    });

    it('releases the mic and the level meter when stopped', async () => {
        const { recorder, meterStops, trackStops, scope } = mount();

        await recorder.start();
        recorder.stop();
        await vi.advanceTimersByTimeAsync(0);

        expect(trackStops()).toBe(1);
        expect(meterStops()).toBe(1);
        scope.stop();
    });

    it('discards the clip when cancelled', async () => {
        const { recorder, recorded, trackStops, scope } = mount();

        await recorder.start();
        recorder.cancel();
        await vi.advanceTimersByTimeAsync(0);

        expect(recorded).toHaveLength(0);
        expect(recorder.isRecording.value).toBe(false);
        expect(trackStops()).toBe(1);
        scope.stop();
    });

    it('auto-stops at the five-minute cap and stages what was recorded', async () => {
        const { recorder, recorded, scope } = mount();

        await recorder.start();
        await vi.advanceTimersByTimeAsync(299_000);
        expect(recorder.isRecording.value).toBe(true);

        await vi.advanceTimersByTimeAsync(1_500);

        expect(recorder.isRecording.value).toBe(false);
        expect(recorded).toHaveLength(1);
        scope.stop();
    });

    it('toasts and stages nothing when the mic is denied', async () => {
        const { recorder, recorded, scope } = mount({
            requestStream: () => Promise.reject(new Error('NotAllowedError')),
        });

        await recorder.start();

        expect(recorder.isRecording.value).toBe(false);
        expect(recorded).toHaveLength(0);
        expect(toastError).toHaveBeenCalledWith(
            'Microphone unavailable',
            expect.objectContaining({
                description:
                    'Allow microphone access in your browser to record a voice message.',
            }),
        );
        scope.stop();
    });

    it('toasts and tears down when the recorder itself errors', async () => {
        const { recorder, recording, trackStops, scope } = mount();

        await recorder.start();
        recording.onerror?.();
        await vi.advanceTimersByTimeAsync(0);

        expect(recorder.isRecording.value).toBe(false);
        expect(trackStops()).toBe(1);
        expect(toastError).toHaveBeenCalled();
        scope.stop();
    });

    it('keeps recording without a level meter the browser cannot provide', async () => {
        const scope = effectScope();
        const { stream } = fakeStream();
        const recording = new FakeRecording();
        const recorder = scope.run(() =>
            useVoiceRecorder({
                onRecorded: () => {},
                requestStream: () => Promise.resolve(stream),
                createRecording: () => recording,
                createLevelMeter: () => null,
            }),
        ) as VoiceRecorder;

        await recorder.start();
        await vi.advanceTimersByTimeAsync(500);

        expect(recorder.isRecording.value).toBe(true);
        expect(recorder.level.value).toBe(0);
        scope.stop();
    });

    it('releases the mic when the owning scope goes away mid-recording', async () => {
        const { recorder, recorded, trackStops, scope } = mount();

        await recorder.start();
        scope.stop();
        await vi.advanceTimersByTimeAsync(0);

        expect(trackStops()).toBe(1);
        expect(recorded).toHaveLength(0);
    });

    it('is a no-op to stop or cancel when idle', () => {
        const { recorder, recorded, scope } = mount();

        expect(() => {
            recorder.stop();
            recorder.cancel();
        }).not.toThrow();
        expect(recorded).toHaveLength(0);
        scope.stop();
    });
});
