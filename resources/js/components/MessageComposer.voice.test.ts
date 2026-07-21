// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App, Component, Ref } from 'vue';
import { createApp, defineComponent, h, nextTick } from 'vue';
import type * as AudioLib from '@/lib/audio';
import { translate } from '@/lib/i18n';
import MessageComposer from './MessageComposer.vue';

/**
 * Covers the composer's voice-message wiring: the mic slot's capability gate,
 * the recording strip that replaces the input row, and a stopped clip landing
 * in the ordinary attachment tray with its inline player. The recorder itself
 * is faked here — its lifecycle is covered in useVoiceRecorder.test.ts.
 */

/** The live refs of the most recently mounted fake recorder, driven by tests. */
interface RecorderRefs {
    isRecording: Ref<boolean>;
    elapsedSeconds: Ref<number>;
    level: Ref<number>;
}

const { supported, recorderState, capturedOptions } = vi.hoisted(() => ({
    supported: { value: true },
    recorderState: {} as { refs: RecorderRefs },
    capturedOptions: { onRecorded: null as ((file: File) => void) | null },
}));

const start = vi.fn();
const stop = vi.fn();
const cancel = vi.fn();

vi.mock('@/lib/audio', async (importOriginal) => ({
    ...(await importOriginal<typeof AudioLib>()),
    isVoiceRecordingSupported: () => supported.value,
    decodeWaveformPeaks: () => Promise.resolve([]),
}));

vi.mock('@/composables/useVoiceRecorder', async () => {
    const { computed, ref: reactive } = await import('vue');

    return {
        useVoiceRecorder: (options: { onRecorded: (file: File) => void }) => {
            capturedOptions.onRecorded = options.onRecorded;

            const isRecording = reactive(false);
            const elapsedSeconds = reactive(0);
            const level = reactive(0);

            // Mirror the fake's live refs back out so a test can drive them.
            recorderState.refs = { isRecording, elapsedSeconds, level };

            return {
                isRecording,
                elapsedSeconds,
                level,
                isNearingLimit: computed(
                    () => 300 - elapsedSeconds.value <= 30 && isRecording.value,
                ),
                start,
                stop,
                cancel,
            };
        },
    };
});

vi.mock('@/actions/App/Http/Controllers/Channels/AttachmentController', () => ({
    store: () => ({ url: '/t/acme/c/general/attachments' }),
}));

vi.mock('@/lib/uploadAttachment', () => ({
    xhrUpload: () => ({
        promise: new Promise(() => {}),
        abort: () => {},
    }),
}));

vi.mock('@/components/ui/button', async () => {
    const { defineComponent: define, h: render } = await import('vue');

    return {
        Button: define({
            name: 'ButtonStub',
            inheritAttrs: false,
            setup:
                (_props, { attrs, slots }) =>
                () =>
                    render('button', attrs, slots.default?.()),
        }),
    };
});

vi.mock('@/components/ui/tooltip', async () => {
    const { defineComponent: define, h: render } = await import('vue');
    const slot = (name: string) =>
        define({
            name,
            setup:
                (_props, { slots }) =>
                () =>
                    render('div', slots.default?.()),
        });

    return {
        Tooltip: slot('TooltipStub'),
        TooltipContent: slot('TooltipContentStub'),
        TooltipProvider: slot('TooltipProviderStub'),
        TooltipTrigger: slot('TooltipTriggerStub'),
    };
});

let active: Array<{ app: App; container: HTMLElement }> = [];

function mountComposer(): HTMLElement {
    const container = document.createElement('div');
    document.body.append(container);

    const app = createApp(
        defineComponent({
            setup: () => () =>
                h(MessageComposer as Component, {
                    channelName: 'general',
                    members: [],
                    teamSlug: 'acme',
                    channelSlug: 'general',
                }),
        }),
    );
    app.config.globalProperties.$t = translate;
    app.mount(container);
    active.push({ app, container });

    return container;
}

function find(container: HTMLElement, hook: string): HTMLElement | null {
    return container.querySelector<HTMLElement>(`[data-test="${hook}"]`);
}

/** Put the faked recorder into its recording state and re-render. */
async function record(elapsedSeconds = 12): Promise<void> {
    recorderState.refs.isRecording.value = true;
    recorderState.refs.elapsedSeconds.value = elapsedSeconds;
    await nextTick();
}

beforeEach(() => {
    supported.value = true;
    start.mockClear();
    stop.mockClear();
    cancel.mockClear();
    vi.stubGlobal('URL', {
        ...URL,
        createObjectURL: () => 'blob:clip',
        revokeObjectURL: () => {},
    });
});

afterEach(() => {
    active.forEach(({ app, container }) => {
        app.unmount();
        container.remove();
    });
    active = [];
    vi.unstubAllGlobals();
});

describe('MessageComposer voice messages', () => {
    it('offers a mic in the trailing controls', () => {
        const container = mountComposer();
        const mic = find(container, 'message-composer-record');

        expect(mic).not.toBeNull();
        expect(mic?.getAttribute('aria-label')).toBe('Record a voice message');
    });

    it('omits the mic entirely where the browser cannot record', () => {
        supported.value = false;

        expect(find(mountComposer(), 'message-composer-record')).toBeNull();
    });

    it('starts recording from the mic', () => {
        const container = mountComposer();

        find(container, 'message-composer-record')?.click();

        expect(start).toHaveBeenCalled();
    });

    it('replaces the input row with the recording strip while recording', async () => {
        const container = mountComposer();

        expect(find(container, 'composer-recording')).toBeNull();

        await record(37);

        expect(find(container, 'composer-recording')).not.toBeNull();
        expect(find(container, 'message-composer-input')).toBeNull();
        expect(
            find(container, 'composer-recording-elapsed')?.textContent,
        ).toContain('0:37');
        expect(find(container, 'composer-recording')?.textContent).toContain(
            '5:00',
        );
    });

    it('warns on the timer only over the final thirty seconds', async () => {
        const container = mountComposer();

        await record(200);
        expect(
            find(container, 'composer-recording-elapsed')?.dataset.warning,
        ).toBe('false');

        await record(280);
        expect(
            find(container, 'composer-recording-elapsed')?.dataset.warning,
        ).toBe('true');
    });

    it('stages the clip from the strip’s stop control', async () => {
        const container = mountComposer();

        await record();
        find(container, 'composer-recording-stop')?.click();

        expect(stop).toHaveBeenCalled();
    });

    it('discards the clip from the strip’s cancel control', async () => {
        const container = mountComposer();

        await record();
        find(container, 'composer-recording-cancel')?.click();

        expect(cancel).toHaveBeenCalled();
    });

    it('stages a finished recording in the ordinary attachment tray', async () => {
        const container = mountComposer();

        capturedOptions.onRecorded?.(
            new File(['clip'], 'voice-message-1721318675.webm', {
                type: 'audio/webm;codecs=opus',
            }),
        );
        await nextTick();

        expect(find(container, 'composer-attachment')).not.toBeNull();
        expect(find(container, 'audio-player')).not.toBeNull();
        // The clip's generated name never reaches the user.
        expect(container.textContent).not.toContain('voice-message-');
    });

    it('previews a dropped audio file with the same player', async () => {
        const container = mountComposer();
        const input = container.querySelector<HTMLInputElement>(
            '[data-test="composer-file-input"]',
        );

        expect(input).not.toBeNull();

        capturedOptions.onRecorded?.(
            new File(['bytes'], 'standup-jingle.mp3', { type: 'audio/mpeg' }),
        );
        await nextTick();

        expect(find(container, 'audio-player-filename')?.textContent).toContain(
            'standup-jingle.mp3',
        );
    });
});
