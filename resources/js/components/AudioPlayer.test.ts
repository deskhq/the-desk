// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, h, nextTick } from 'vue';
import { translate } from '@/lib/i18n';
import AudioPlayer from './AudioPlayer.vue';

/**
 * Covers the inline audio player every `audio/*` attachment renders as: its
 * two chrome shapes (a recorded clip vs a dropped file), the client-side peak
 * decode, seeking, and the `MediaRecorder` `duration === Infinity` workaround.
 * jsdom implements no media pipeline, so the element's playback surface is
 * stubbed and driven by hand.
 */

vi.mock('@/components/ui/button', async () => {
    const { defineComponent, h: render } = await import('vue');

    return {
        Button: defineComponent({
            name: 'ButtonStub',
            inheritAttrs: false,
            setup:
                (_props, { attrs, slots }) =>
                () =>
                    render('button', attrs, slots.default?.()),
        }),
    };
});

let mounted: App | null = null;
let host: HTMLElement | null = null;

/** Stub Web Audio + fetch so the peak decode resolves to known bars. */
function stubDecode(samples: number[]): void {
    vi.stubGlobal('fetch', () =>
        Promise.resolve({
            arrayBuffer: () => Promise.resolve(new ArrayBuffer(8)),
        }),
    );
    vi.stubGlobal(
        'AudioContext',
        class {
            close = vi.fn();
            decodeAudioData(): Promise<{ getChannelData: () => Float32Array }> {
                return Promise.resolve({
                    getChannelData: () => new Float32Array(samples),
                });
            }
        },
    );
}

function mount(props: {
    src: string;
    filename?: string | null;
    compact?: boolean;
    bars?: number;
}): HTMLElement {
    host = document.createElement('div');
    document.body.append(host);
    mounted = createApp({ render: () => h(AudioPlayer, props) });
    mounted.config.globalProperties.$t = translate;
    mounted.mount(host);

    return host;
}

function audioElement(): HTMLAudioElement {
    const element = host?.querySelector('audio');

    if (!element) {
        throw new Error('the player rendered no audio element');
    }

    return element;
}

/** Give the element a duration jsdom will not compute on its own. */
function setDuration(seconds: number): void {
    Object.defineProperty(audioElement(), 'duration', {
        configurable: true,
        get: () => seconds,
    });
}

function fire(event: string): void {
    audioElement().dispatchEvent(new Event(event));
}

function text(selector: string): string {
    return host?.querySelector(selector)?.textContent?.trim() ?? '';
}

describe('AudioPlayer', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', () => Promise.reject(new Error('no network')));
        Object.defineProperty(HTMLMediaElement.prototype, 'play', {
            configurable: true,
            value: vi.fn(() => Promise.resolve()),
        });
        Object.defineProperty(HTMLMediaElement.prototype, 'pause', {
            configurable: true,
            value: vi.fn(),
        });
        // jsdom exposes no seekable timeline, so give the element a real one.
        Object.defineProperty(HTMLMediaElement.prototype, 'currentTime', {
            configurable: true,
            get(): number {
                return (this as { _time?: number })._time ?? 0;
            },
            set(seconds: number) {
                (this as { _time?: number })._time = seconds;
            },
        });
    });

    afterEach(() => {
        mounted?.unmount();
        host?.remove();
        mounted = null;
        host = null;
        vi.unstubAllGlobals();
    });

    it('shows a dropped audio file’s name', () => {
        mount({
            src: 'https://desk.test/a.mp3',
            filename: 'standup-jingle.mp3',
        });

        expect(text('[data-test="audio-player-filename"]')).toBe(
            'standup-jingle.mp3',
        );
    });

    it('renders compact chrome with no filename line for a recorded clip', () => {
        mount({
            src: 'https://desk.test/b.webm',
            filename: 'voice-message-1721318675.webm',
        });

        expect(host?.querySelector('[data-test="audio-player-filename"]')).toBe(
            null,
        );
    });

    it('resolves a MediaRecorder clip’s Infinity duration before showing it', async () => {
        mount({ src: 'https://desk.test/c.webm', filename: null });

        setDuration(Number.POSITIVE_INFINITY);
        fire('loadedmetadata');
        await nextTick();

        expect(text('[data-test="audio-player-duration"]')).toBe('0:00');
        // The workaround seeks past the end so the browser computes the real
        // length, then rewinds once a timeupdate reports it.
        expect(audioElement().currentTime).toBeGreaterThan(0);

        setDuration(37);
        fire('timeupdate');
        await nextTick();

        expect(text('[data-test="audio-player-duration"]')).toBe('0:37');
        expect(audioElement().currentTime).toBe(0);
    });

    it('reads a well-formed clip’s duration straight from the element', async () => {
        mount({ src: 'https://desk.test/d.mp3', filename: 'jingle.mp3' });

        setDuration(64);
        fire('loadedmetadata');
        await nextTick();

        expect(text('[data-test="audio-player-duration"]')).toBe('1:04');
    });

    it('tracks playback progress on the scrubber', async () => {
        mount({ src: 'https://desk.test/e.mp3', filename: 'jingle.mp3' });

        setDuration(40);
        fire('loadedmetadata');
        audioElement().currentTime = 10;
        fire('timeupdate');
        await nextTick();

        expect(text('[data-test="audio-player-elapsed"]')).toBe('0:10');
        expect(
            host
                ?.querySelector('[data-test="audio-player-scrubber"]')
                ?.getAttribute('aria-valuenow'),
        ).toBe('10');
    });

    it('plays and pauses from the toggle', async () => {
        mount({ src: 'https://desk.test/f.mp3', filename: 'jingle.mp3' });

        const toggle = host?.querySelector<HTMLElement>(
            '[data-test="audio-player-toggle"]',
        );

        expect(toggle?.getAttribute('aria-label')).toBe('Play');

        toggle?.click();
        fire('play');
        await nextTick();

        expect(audioElement().play).toHaveBeenCalled();
        expect(toggle?.getAttribute('aria-label')).toBe('Pause');

        toggle?.click();
        await nextTick();

        expect(audioElement().pause).toHaveBeenCalled();
    });

    it('seeks with the keyboard from the scrubber', async () => {
        mount({ src: 'https://desk.test/g.mp3', filename: 'jingle.mp3' });

        setDuration(100);
        fire('loadedmetadata');
        await nextTick();

        const scrubber = host?.querySelector<HTMLElement>(
            '[data-test="audio-player-scrubber"]',
        );

        scrubber?.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }),
        );
        expect(audioElement().currentTime).toBe(5);

        scrubber?.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'End', bubbles: true }),
        );
        expect(audioElement().currentTime).toBe(100);

        scrubber?.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Home', bubbles: true }),
        );
        expect(audioElement().currentTime).toBe(0);
    });

    it('exposes the scrubber as a labelled slider for assistive tech', async () => {
        mount({ src: 'https://desk.test/h.mp3', filename: 'jingle.mp3' });

        setDuration(37);
        fire('loadedmetadata');
        await nextTick();

        const scrubber = host?.querySelector(
            '[data-test="audio-player-scrubber"]',
        );

        expect(scrubber?.getAttribute('role')).toBe('slider');
        expect(scrubber?.getAttribute('tabindex')).toBe('0');
        expect(scrubber?.getAttribute('aria-label')).toBe('Seek');
        expect(scrubber?.getAttribute('aria-valuemax')).toBe('37');
        expect(scrubber?.getAttribute('aria-valuetext')).toBe('0:00 of 0:37');
    });

    it('draws a decoded waveform, filling the played portion', async () => {
        stubDecode([1, 0.5, 0.25, 0]);
        mount({ src: 'https://desk.test/i.webm', filename: null, bars: 4 });

        await vi.waitFor(() => {
            expect(
                host?.querySelectorAll('[data-test="audio-player-bar"]').length,
            ).toBe(4);
        });

        setDuration(40);
        fire('loadedmetadata');
        audioElement().currentTime = 20;
        fire('timeupdate');
        await nextTick();

        const played = host?.querySelectorAll(
            '[data-test="audio-player-bar"][data-played="true"]',
        );

        expect(played?.length).toBe(2);
    });

    it('falls back to a plain progress bar when the clip cannot be decoded', async () => {
        mount({ src: 'https://desk.test/j.mp3', filename: 'jingle.mp3' });

        await nextTick();
        await nextTick();

        expect(host?.querySelector('[data-test="audio-player-bar"]')).toBe(
            null,
        );
        expect(
            host?.querySelector('[data-test="audio-player-track"]'),
        ).not.toBe(null);
    });

    it('rewinds to the start once the clip ends', async () => {
        mount({ src: 'https://desk.test/k.mp3', filename: 'jingle.mp3' });

        setDuration(30);
        fire('loadedmetadata');
        audioElement().currentTime = 30;
        fire('ended');
        await nextTick();

        expect(audioElement().currentTime).toBe(0);
        expect(
            host
                ?.querySelector('[data-test="audio-player-toggle"]')
                ?.getAttribute('aria-label'),
        ).toBe('Play');
    });
});
