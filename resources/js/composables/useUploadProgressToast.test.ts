import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';
import type { EffectScope, Ref } from 'vue';

/**
 * Covers the toast that reports uploads the composer is no longer showing: when
 * it appears at all, which rows it counts, what it says while they run, and
 * what it becomes when they land. The arithmetic is `lib/uploadBatch`'s and the
 * rows are the registry's; what is watched here is the decision to speak.
 */
const { toast } = vi.hoisted(() => ({
    toast: {
        success: vi.fn(),
        error: vi.fn(),
        warning: vi.fn(),
        progress: vi.fn(),
        dismiss: vi.fn(),
    },
}));

vi.mock('@/composables/useToast', () => ({ useToast: () => toast }));

import {
    channelUploads,
    releaseChannelUploads,
} from '@/composables/useChannelUploads';
import { useUploadProgressToast } from '@/composables/useUploadProgressToast';
import {
    attachment,
    buildRegistry,
    fakeFile,
} from './useAttachmentUploads.doubles';
import type { RegistryHarness } from './useAttachmentUploads.doubles';

const MB = 1024 * 1024;
const DESIGN = 'acme/design';
const GENERAL = 'acme/general';

describe('useUploadProgressToast', () => {
    let registry: RegistryHarness;
    let scope: EffectScope;
    let onScreen: Ref<string | null>;
    let opened: string[];
    let clock: number;

    /** The options the last toast of the given tone was raised with. */
    function optionsOf(tone: 'progress' | 'success' | 'error') {
        const { calls } = toast[tone].mock;

        return calls[calls.length - 1][1] as {
            key?: string;
            meta?: string;
            value?: string;
            action?: { label: string; run: () => void };
        };
    }

    /** The title of the last toast of the given tone. */
    function titleOf(tone: 'progress' | 'success' | 'error'): string {
        const { calls } = toast[tone].mock;

        return calls[calls.length - 1][0] as string;
    }

    /** Let the watcher see everything staged since the last one. */
    async function settle(): Promise<void> {
        await Promise.resolve();
        await Promise.resolve();
        await nextTick();
    }

    /** Move the clock past the throttle window. */
    function tick(): void {
        clock += 1_500;
    }

    beforeEach(() => {
        vi.clearAllMocks();
        registry = buildRegistry();
        onScreen = ref<string | null>(DESIGN);
        opened = [];
        clock = 0;

        scope = effectScope();
        scope.run(() => {
            useUploadProgressToast({
                channels: () => channelUploads.value,
                activeChannelKey: () => onScreen.value,
                channelName: (channelKey) =>
                    channelKey === DESIGN ? '#design' : null,
                openChannel: (channelKey) => opened.push(channelKey),
                now: () => clock,
            });
        });
    });

    afterEach(() => {
        scope.stop();
        registry.closeAll();
        releaseChannelUploads();
    });

    it('says nothing while the uploading channel is the one on screen', async () => {
        const design = registry.open(DESIGN);
        design.uploads.addFiles([fakeFile('a.pdf', 'application/pdf', MB)]);
        registry.calls[0].onProgress(40);
        await settle();

        expect(toast.progress).not.toHaveBeenCalled();
    });

    it('appears mid-flight at whatever percent it has reached when you leave', async () => {
        const design = registry.open(DESIGN);
        design.uploads.addFiles([
            fakeFile('a.pdf', 'application/pdf', 12 * MB),
        ]);
        registry.calls[0].onProgress(64);
        await settle();

        onScreen.value = GENERAL;
        await settle();

        expect(titleOf('progress')).toBe('Uploading 1 file');
        expect(optionsOf('progress')).toMatchObject({
            key: 'attachment-uploads',
            meta: '12 MB',
            value: '64%',
        });
    });

    it('counts two channels into one card rather than stacking two', async () => {
        const design = registry.open(DESIGN);
        design.uploads.addFiles([fakeFile('a.pdf', 'application/pdf', MB)]);
        const general = registry.open(GENERAL);
        general.uploads.addFiles([
            fakeFile('b.pdf', 'application/pdf', MB),
            fakeFile('c.pdf', 'application/pdf', MB),
        ]);

        onScreen.value = null;
        await settle();

        expect(titleOf('progress')).toBe('Uploading 3 files');
        expect(toast.progress).toHaveBeenCalledTimes(1);
    });

    it('leaves out rows that had already landed before it appeared', async () => {
        const design = registry.open(DESIGN);
        design.uploads.addFiles([
            fakeFile('a.pdf', 'application/pdf', MB),
            fakeFile('b.pdf', 'application/pdf', MB),
            fakeFile('c.pdf', 'application/pdf', MB),
        ]);
        registry.calls[0].resolve(attachment('att-1'));
        registry.calls[1].resolve(attachment('att-2'));
        await settle();

        onScreen.value = GENERAL;
        await settle();

        expect(titleOf('progress')).toBe('Uploading 1 file');
    });

    it('keeps the percent climbing when one of the batch lands', async () => {
        const design = registry.open(DESIGN);
        design.uploads.addFiles([
            fakeFile('a.pdf', 'application/pdf', MB),
            fakeFile('b.pdf', 'application/pdf', MB),
        ]);
        registry.calls[0].onProgress(50);
        registry.calls[1].onProgress(50);
        onScreen.value = GENERAL;
        await settle();

        expect(optionsOf('progress').value).toBe('50%');

        registry.calls[0].resolve(attachment('att-1'));
        tick();
        await settle();

        // The finished row stays in the denominator at 100 rather than leaving
        // the set and dragging both the count and the figure backwards.
        expect(titleOf('progress')).toBe('Uploading 2 files');
        expect(optionsOf('progress').value).toBe('75%');
    });

    it('takes a row that starts uploading later into the batch', async () => {
        const design = registry.open(DESIGN);
        design.uploads.addFiles([fakeFile('a.pdf', 'application/pdf', MB)]);
        onScreen.value = GENERAL;
        await settle();

        const general = registry.open(GENERAL);
        expect(titleOf('progress')).toBe('Uploading 1 file');

        // Uploaded from the drag-and-drop overlay of a channel the user then
        // leaves again — it joins the card already on screen.
        general.uploads.addFiles([fakeFile('b.pdf', 'application/pdf', MB)]);
        onScreen.value = null;
        tick();
        await settle();

        expect(titleOf('progress')).toBe('Uploading 2 files');
    });

    it('redraws about once a second rather than on every chunk', async () => {
        const design = registry.open(DESIGN);
        design.uploads.addFiles([fakeFile('a.pdf', 'application/pdf', MB)]);
        onScreen.value = GENERAL;
        await settle();
        expect(toast.progress).toHaveBeenCalledTimes(1);

        for (const percent of [11, 12, 13, 14]) {
            registry.calls[0].onProgress(percent);
            await settle();
        }

        expect(toast.progress).toHaveBeenCalledTimes(1);

        tick();
        registry.calls[0].onProgress(15);
        await settle();

        expect(toast.progress).toHaveBeenCalledTimes(2);
        expect(optionsOf('progress').value).toBe('15%');
    });

    it('is taken away, not resolved, when you come back to the channel', async () => {
        const design = registry.open(DESIGN);
        design.uploads.addFiles([fakeFile('a.pdf', 'application/pdf', MB)]);
        onScreen.value = GENERAL;
        await settle();

        onScreen.value = DESIGN;
        await settle();

        expect(toast.dismiss).toHaveBeenCalledWith('attachment-uploads');
        expect(toast.success).not.toHaveBeenCalled();
    });

    it('says nothing when the batch lands after you are already back', async () => {
        const design = registry.open(DESIGN);
        design.uploads.addFiles([fakeFile('a.pdf', 'application/pdf', MB)]);
        onScreen.value = GENERAL;
        await settle();

        onScreen.value = DESIGN;
        await settle();

        registry.calls[0].resolve(attachment('att-1'));
        tick();
        await settle();

        // The tray in front of the user already said so.
        expect(toast.success).not.toHaveBeenCalled();
    });

    it('confirms a landed batch without claiming anything was sent', async () => {
        const design = registry.open(DESIGN);
        design.uploads.addFiles([
            fakeFile('a.pdf', 'application/pdf', MB),
            fakeFile('b.pdf', 'application/pdf', MB),
        ]);
        onScreen.value = GENERAL;
        await settle();

        registry.calls[0].resolve(attachment('att-1'));
        registry.calls[1].resolve(attachment('att-2'));
        tick();
        await settle();

        expect(titleOf('success')).toBe('2 files ready to send');
        expect(optionsOf('success')).toMatchObject({
            key: 'attachment-uploads',
            meta: '#design',
        });

        optionsOf('success').action?.run();
        expect(opened).toEqual([DESIGN]);
    });

    it('names no channel for a batch that spans several', async () => {
        const design = registry.open(DESIGN);
        design.uploads.addFiles([fakeFile('a.pdf', 'application/pdf', MB)]);
        const general = registry.open(GENERAL);
        general.uploads.addFiles([fakeFile('b.pdf', 'application/pdf', MB)]);
        onScreen.value = null;
        await settle();

        registry.calls[0].resolve(attachment('att-1'));
        registry.calls[1].resolve(attachment('att-2'));
        tick();
        await settle();

        expect(titleOf('success')).toBe('2 files ready to send');
        expect(optionsOf('success').meta).toBeUndefined();
        expect(optionsOf('success').action).toBeUndefined();
    });

    it('names only the failures, and the retry re-enters progress under the same key', async () => {
        const design = registry.open(DESIGN);
        design.uploads.addFiles([
            fakeFile('a.pdf', 'application/pdf', MB),
            fakeFile('b.pdf', 'application/pdf', MB),
        ]);
        onScreen.value = GENERAL;
        await settle();

        registry.calls[0].resolve(attachment('att-1'));
        registry.calls[1].reject({
            status: 500,
            message: null,
            aborted: false,
        });
        tick();
        await settle();

        expect(titleOf('error')).toBe("1 file didn't upload");
        expect(optionsOf('error').key).toBe('attachment-uploads');

        optionsOf('error').action?.run();
        tick();
        await settle();

        // Back to the progress tone under the same key: no new card, no new key.
        expect(registry.calls).toHaveLength(3);
        expect(titleOf('progress')).toBe('Uploading 1 file');
        expect(optionsOf('progress').key).toBe('attachment-uploads');
    });

    it('never counts a picked GIF, which arrives with nothing to transfer', async () => {
        const design = registry.open(DESIGN);
        design.uploads.addRemote({
            id: 'gif-1',
            filename: null,
            mimeType: 'image/gif',
            sizeBytes: 4 * MB,
            width: 2,
            height: 1,
            isImage: true,
            source: 'giphy',
            url: 'https://media.giphy.com/gif-1/200.gif',
            thumbUrl: null,
            description: null,
        });

        onScreen.value = GENERAL;
        await settle();

        expect(toast.progress).not.toHaveBeenCalled();
    });
});
