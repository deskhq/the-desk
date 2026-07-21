import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    VOICE_MAX_DURATION_SECONDS,
    VOICE_MESSAGE_PREFIX,
    VOICE_WARNING_SECONDS,
    audioExtensionFor,
    decodeWaveformPeaks,
    formatClock,
    isAudioMime,
    isVoiceMessageFilename,
    isVoiceRecordingSupported,
    voiceMessageFilename,
    waveformPeaks,
} from './audio';

describe('isAudioMime', () => {
    it.each([
        ['audio/webm', true],
        ['audio/webm;codecs=opus', true],
        ['AUDIO/MP4', true],
        ['audio/mpeg', true],
        ['image/png', false],
        ['application/pdf', false],
        ['video/mp4', false],
        ['', false],
    ])('classifies %s as audio: %s', (mime, expected) => {
        expect(isAudioMime(mime)).toBe(expected);
    });
});

describe('isVoiceMessageFilename', () => {
    it('recognises a clip recorded in the composer', () => {
        expect(isVoiceMessageFilename('voice-message-1721318675.webm')).toBe(
            true,
        );
    });

    it('treats a dropped audio file as an ordinary attachment', () => {
        expect(isVoiceMessageFilename('standup-jingle.mp3')).toBe(false);
    });

    it('treats a filename-less (remote) attachment as an ordinary one', () => {
        expect(isVoiceMessageFilename(null)).toBe(false);
    });
});

describe('audioExtensionFor', () => {
    it.each([
        ['audio/webm;codecs=opus', 'webm'],
        ['audio/webm', 'webm'],
        ['audio/mp4', 'm4a'],
        ['audio/mp4;codecs=mp4a.40.2', 'm4a'],
        ['audio/mpeg', 'mp3'],
        ['audio/ogg;codecs=opus', 'ogg'],
        ['audio/wav', 'wav'],
        ['audio/x-wav', 'wav'],
        ['', 'webm'],
        ['audio/exotic', 'webm'],
    ])('maps %s to .%s', (mime, expected) => {
        expect(audioExtensionFor(mime)).toBe(expected);
    });
});

describe('voiceMessageFilename', () => {
    it('names a clip from the prefix, the whole-second timestamp, and the real mime', () => {
        expect(
            voiceMessageFilename('audio/webm;codecs=opus', 1_721_318_675_412),
        ).toBe('voice-message-1721318675.webm');
    });

    it('follows the mime on Safari', () => {
        expect(voiceMessageFilename('audio/mp4', 1_721_318_675_000)).toBe(
            'voice-message-1721318675.m4a',
        );
    });

    it('always starts with the prefix the player branches on', () => {
        expect(
            voiceMessageFilename('audio/webm', 1_000).startsWith(
                VOICE_MESSAGE_PREFIX,
            ),
        ).toBe(true);
    });
});

describe('formatClock', () => {
    it.each([
        [0, '0:00'],
        [7, '0:07'],
        [37, '0:37'],
        [59.9, '0:59'],
        [60, '1:00'],
        [VOICE_MAX_DURATION_SECONDS, '5:00'],
        [3_600, '1:00:00'],
        [3_661, '1:01:01'],
    ])('formats %s seconds as %s', (seconds, expected) => {
        expect(formatClock(seconds)).toBe(expected);
    });

    it.each([Number.NaN, Number.POSITIVE_INFINITY, -5])(
        'falls back to zero for the unresolved duration %s',
        (seconds) => {
            expect(formatClock(seconds)).toBe('0:00');
        },
    );
});

describe('waveformPeaks', () => {
    it('reduces samples to the requested number of buckets', () => {
        const samples = new Float32Array(100).fill(0.5);

        expect(waveformPeaks(samples, 10)).toHaveLength(10);
    });

    it('normalises the loudest bucket to 1 so a quiet clip still draws', () => {
        const samples = new Float32Array([0.01, 0.01, 0.05, 0.05]);
        const peaks = waveformPeaks(samples, 2);

        expect(peaks[0]).toBeCloseTo(0.2, 5);
        expect(peaks[1]).toBeCloseTo(1, 5);
    });

    it('measures each bucket by its loudest absolute sample', () => {
        const samples = new Float32Array([0, -1, 0.5, 0.25]);

        expect(waveformPeaks(samples, 2)).toEqual([1, 0.5]);
    });

    it('returns silent buckets for a digitally silent clip', () => {
        expect(waveformPeaks(new Float32Array(8), 4)).toEqual([0, 0, 0, 0]);
    });

    it('returns nothing when there is no audio to measure', () => {
        expect(waveformPeaks(new Float32Array(0), 8)).toEqual([]);
        expect(waveformPeaks(new Float32Array(8), 0)).toEqual([]);
    });

    it('never leaves a bucket empty when there are fewer samples than buckets', () => {
        const peaks = waveformPeaks(new Float32Array([1, 0.5]), 4);

        expect(peaks).toHaveLength(4);
        expect(peaks.every((peak) => Number.isFinite(peak))).toBe(true);
    });
});

describe('decodeWaveformPeaks', () => {
    const original = {
        fetch: globalThis.fetch,
        AudioContext: (globalThis as { AudioContext?: unknown }).AudioContext,
    };

    /** Stub Web Audio and the network with a clip of the given samples. */
    function stubAudio(samples: number[]): {
        fetchSpy: ReturnType<typeof vi.fn>;
        close: ReturnType<typeof vi.fn>;
    } {
        const fetchSpy = vi.fn(() =>
            Promise.resolve({
                arrayBuffer: () => Promise.resolve(new ArrayBuffer(8)),
            }),
        );
        const close = vi.fn();

        vi.stubGlobal('fetch', fetchSpy);
        vi.stubGlobal(
            'AudioContext',
            class {
                close = close;
                decodeAudioData(): Promise<{
                    getChannelData: () => Float32Array;
                }> {
                    return Promise.resolve({
                        getChannelData: () => new Float32Array(samples),
                    });
                }
            },
        );

        return { fetchSpy, close };
    }

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.stubGlobal('fetch', original.fetch);
        vi.stubGlobal('AudioContext', original.AudioContext);
        vi.unstubAllGlobals();
    });

    it('decodes a clip into normalised peaks and closes the context', async () => {
        const { close } = stubAudio([0, 1, 0.5, 0.5]);

        await expect(decodeWaveformPeaks('blob:one', 2)).resolves.toEqual([
            1, 0.5,
        ]);
        expect(close).toHaveBeenCalled();
    });

    it('decodes a clip only once, so a remounting player is free', async () => {
        const { fetchSpy } = stubAudio([0, 1]);

        await decodeWaveformPeaks('blob:two', 2);
        await decodeWaveformPeaks('blob:two', 2);

        expect(fetchSpy).toHaveBeenCalledTimes(1);
    });

    it('yields no peaks when the clip cannot be decoded', async () => {
        vi.stubGlobal('fetch', () => Promise.reject(new Error('offline')));
        vi.stubGlobal(
            'AudioContext',
            class {
                close = vi.fn();
                decodeAudioData = vi.fn();
            },
        );

        await expect(decodeWaveformPeaks('blob:three', 8)).resolves.toEqual([]);
    });

    it('yields no peaks in a browser without Web Audio', async () => {
        vi.stubGlobal('AudioContext', undefined);

        await expect(decodeWaveformPeaks('blob:four', 8)).resolves.toEqual([]);
    });
});

describe('isVoiceRecordingSupported', () => {
    const capable = {
        MediaRecorder: class {},
        isSecureContext: true,
        navigator: { mediaDevices: { getUserMedia: () => {} } },
    };

    it('reports support where MediaRecorder and getUserMedia exist securely', () => {
        expect(isVoiceRecordingSupported(capable)).toBe(true);
    });

    it('reports no support without MediaRecorder', () => {
        expect(
            isVoiceRecordingSupported({ ...capable, MediaRecorder: undefined }),
        ).toBe(false);
    });

    it('reports no support outside a secure context', () => {
        expect(
            isVoiceRecordingSupported({ ...capable, isSecureContext: false }),
        ).toBe(false);
    });

    it('reports no support without getUserMedia', () => {
        expect(isVoiceRecordingSupported({ ...capable, navigator: {} })).toBe(
            false,
        );
        expect(
            isVoiceRecordingSupported({
                ...capable,
                navigator: { mediaDevices: {} },
            }),
        ).toBe(false);
    });
});

describe('recording constants', () => {
    it('caps a recording at five minutes, warning over the final thirty seconds', () => {
        expect(VOICE_MAX_DURATION_SECONDS).toBe(300);
        expect(VOICE_WARNING_SECONDS).toBe(30);
    });
});
