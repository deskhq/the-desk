// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import type * as AudioLib from '@/lib/audio';

/**
 * Covers the composer's phone layout: the one trailing disc that morphs between
 * mic and Send, and the attach sheet the leading `+` raises. Mounted below the
 * breakpoint, which is the only place either exists — the desktop layout every
 * other composer suite mounts is deliberately untouched by all of this. The
 * harness itself lives in MessageComposer.mobile.doubles.ts.
 */

vi.mock('@/lib/uploadAttachment', async () => {
    const { fakeUpload } = await import('./MessageComposer.mobile.stubs');

    return { xhrUpload: fakeUpload };
});

vi.mock('@/actions/App/Http/Controllers/Channels/AttachmentController', () => ({
    store: () => ({ url: '/t/acme/c/general/attachments' }),
}));

vi.mock('@/lib/audio', async (importOriginal) => {
    const { recordingSupported } =
        await import('./MessageComposer.mobile.stubs');

    return {
        ...(await importOriginal<typeof AudioLib>()),
        isVoiceRecordingSupported: () => recordingSupported.value,
    };
});

vi.mock('@/components/ui/dialog', async () => {
    const { dialogStubs } = await import('./MessageComposer.mobile.stubs');

    return dialogStubs();
});

vi.mock('@/components/ui/button', async () => {
    const { buttonStub } = await import('./MessageComposer.mobile.stubs');

    return buttonStub();
});

vi.mock('@/components/ui/tooltip', async () => {
    const { tooltipStubs } = await import('./MessageComposer.mobile.stubs');

    return tooltipStubs();
});

import {
    atViewportWidth,
    field,
    find,
    mountComposer,
    openSheet,
    stage,
    tile,
    tiles,
    type,
    unmountAll,
} from './MessageComposer.mobile.doubles';
import {
    pendingUploads,
    recordingSupported,
} from './MessageComposer.mobile.stubs';

const SLASH_COMMANDS = [
    { name: 'shrug', description: 'Append a shrug', usage: '/shrug' },
] as unknown as App.Data.SlashCommandData[];

function textFile(name = 'notes.txt'): File {
    return new File(['x'], name, { type: 'text/plain' });
}

beforeEach(() => {
    atViewportWidth(390);
    recordingSupported.value = true;
    pendingUploads.length = 0;
    URL.createObjectURL = vi.fn(() => 'blob:preview');
    URL.revokeObjectURL = vi.fn();
});

afterEach(unmountAll);

describe('the morphing action disc', () => {
    it('is a mic while the composer is empty', () => {
        const container = mountComposer();
        const mic = find(container, 'message-composer-record');

        expect(mic).not.toBeNull();
        expect(find(container, 'message-composer-send')).toBeNull();
        expect(mic?.getAttribute('aria-label')).toBe('Record a voice message');
    });

    it('becomes Send on the first keystroke', async () => {
        const container = mountComposer();

        await type(container, 'h');

        const send = find(container, 'message-composer-send');

        expect(send).not.toBeNull();
        expect(find(container, 'message-composer-record')).toBeNull();
        expect(send?.getAttribute('aria-label')).toBe('Send message');
        expect(send?.hasAttribute('disabled')).toBe(false);
    });

    it('becomes Send on a staged attachment with no text at all', async () => {
        const container = mountComposer();

        await stage(container, 'composer-file-input', textFile());
        pendingUploads[0]?.resolve({ id: 'att-1' });
        await nextTick();
        await nextTick();

        expect(find(container, 'message-composer-send')).not.toBeNull();
        expect(find(container, 'message-composer-record')).toBeNull();
    });

    it('is a disabled Send mid-upload, never a mic', async () => {
        const container = mountComposer();

        await stage(container, 'composer-file-input', textFile());

        // The upload is still in flight, so `canSubmit` is false — but the disc
        // must not fall back to a mic and start recording on the next tap.
        expect(find(container, 'message-composer-record')).toBeNull();
        expect(
            find(container, 'message-composer-send')?.hasAttribute('disabled'),
        ).toBe(true);
    });

    it('is a disabled Send where the browser cannot record and there is nothing to send', () => {
        recordingSupported.value = false;

        const container = mountComposer();
        const send = find(container, 'message-composer-send');

        expect(find(container, 'message-composer-record')).toBeNull();
        expect(send?.hasAttribute('disabled')).toBe(true);
    });

    it('keeps one element of one size across the first keystroke', async () => {
        const container = mountComposer();
        const sizeOf = (el: HTMLElement) =>
            el.className
                .split(/\s+/)
                .filter((token) => token.startsWith('size-'));
        const before = sizeOf(find(container, 'message-composer-record')!);

        await type(container, 'hello');

        expect(sizeOf(find(container, 'message-composer-send')!)).toEqual(
            before,
        );
        expect(before).toContain('size-12');
    });
});

describe('the attach sheet', () => {
    it('is raised by the `+` and lowered by a second tap on it', async () => {
        const container = mountComposer();
        const toggle = find(container, 'composer-attach-sheet-toggle')!;

        expect(toggle.getAttribute('aria-expanded')).toBe('false');
        expect(find(container, 'composer-attach-sheet')).toBeNull();

        await openSheet(container);

        expect(find(container, 'composer-attach-sheet')).not.toBeNull();
        expect(toggle.getAttribute('aria-expanded')).toBe('true');
        // The glyph rotates into the x that doubles as the sheet's close.
        expect(toggle.querySelector('.rotate-45')).not.toBeNull();

        toggle.click();
        await nextTick();

        expect(find(container, 'composer-attach-sheet')).toBeNull();
    });

    it('retires the inline tools row it replaces', async () => {
        const container = mountComposer();

        expect(find(container, 'composer-tools')).toBeNull();
        expect(find(container, 'composer-tools-toggle')).toBeNull();

        await openSheet(container);

        // Formatting keeps its hook, on the sheet's strip instead.
        expect(find(container, 'composer-format-cluster')).not.toBeNull();
    });

    it('offers every tile the channel composer can reach', async () => {
        const container = mountComposer({
            slashCommands: SLASH_COMMANDS,
            gifPickerEnabled: true,
            pollsEnabled: true,
        });

        await openSheet(container);

        expect(tiles(container)).toEqual([
            'photos',
            'camera',
            'file',
            'gif',
            'poll',
            'voice',
            'command',
        ]);
    });

    it('hides the tiles whose feature is switched off', async () => {
        const container = mountComposer({ slashCommands: SLASH_COMMANDS });

        await openSheet(container);

        expect(tiles(container)).not.toContain('gif');
        expect(tiles(container)).not.toContain('poll');
    });

    it('hides every tile where the composer has no channel', async () => {
        const container = mountComposer({
            teamSlug: undefined,
            channelSlug: undefined,
        });

        await openSheet(container);

        // A thread's sheet is the format strip alone, until thread attachments
        // land and fill it in for free.
        expect(tiles(container)).toEqual([]);
        expect(find(container, 'composer-format-cluster')).not.toBeNull();
    });

    it('hides the voice tile where the browser cannot record', async () => {
        recordingSupported.value = false;

        const container = mountComposer();

        await openSheet(container);

        expect(tiles(container)).not.toContain('voice');
    });

    it('drops the command tile once a `/` would no longer sit at position 0', async () => {
        const container = mountComposer({ slashCommands: SLASH_COMMANDS });

        await openSheet(container);
        expect(tiles(container)).toContain('command');

        find(container, 'composer-attach-sheet-toggle')!.click();
        await type(container, 'half a thought');
        await openSheet(container);

        expect(tiles(container)).not.toContain('command');
    });

    it('ships the camera tile disabled until native capture lands', async () => {
        const container = mountComposer();

        await openSheet(container);

        const camera = tile(container, 'camera');

        expect(camera.hasAttribute('disabled')).toBe(true);
        expect(camera.getAttribute('aria-label')).toBe('Camera (coming later)');
    });
});

describe('the attach sheet’s tiles', () => {
    it('stages a photo through its own media-restricted input', async () => {
        const container = mountComposer();
        const photoInput = container.querySelector<HTMLInputElement>(
            '[data-test="composer-photo-input"]',
        );

        expect(photoInput?.getAttribute('accept')).toBe('image/*,video/*');
        expect(photoInput?.hasAttribute('multiple')).toBe(true);

        await stage(
            container,
            'composer-photo-input',
            new File(['x'], 'beach.png', { type: 'image/png' }),
        );

        expect(find(container, 'composer-attachment')).not.toBeNull();
    });

    it('opens the GIF picker and keeps the draft the user was part-way through', async () => {
        const container = mountComposer({ gifPickerEnabled: true });

        await type(container, 'look at this');
        await openSheet(container);
        tile(container, 'gif').click();
        await nextTick();

        expect(find(container, 'gif-picker')).not.toBeNull();
        // Typing `/gif` still clears, because there the text *is* the command.
        expect(field(container).value).toBe('look at this');
        // Every tile leaves the sheet behind.
        expect(find(container, 'composer-attach-sheet')).toBeNull();
    });

    it('opens the poll builder and keeps the draft too', async () => {
        const container = mountComposer({ pollsEnabled: true });

        await type(container, 'about lunch');
        await openSheet(container);
        tile(container, 'poll').click();
        await nextTick();

        expect(find(container, 'poll-builder')).not.toBeNull();
        expect(field(container).value).toBe('about lunch');
    });

    it('seeds the field with a slash and opens the command menu', async () => {
        const container = mountComposer({ slashCommands: SLASH_COMMANDS });

        await openSheet(container);
        tile(container, 'command').click();
        await nextTick();
        await nextTick();

        expect(field(container).value).toBe('/');
        expect(find(container, 'slash-menu')).not.toBeNull();
    });
});

describe('the attach sheet’s format strip', () => {
    it('marks up the selection the field held when the sheet opened', async () => {
        const container = mountComposer();

        await type(container, 'make me bold');
        field(container).setSelectionRange(8, 12);

        await openSheet(container);
        // Behind the sheet the field has neither focus nor a live selection, so
        // a strip reading the textarea would wrap nothing at all.
        field(container).setSelectionRange(0, 0);
        find(container, 'message-composer-format-**')!.click();
        await nextTick();

        expect(field(container).value).toBe('make me **bold**');
    });

    it('stays open across taps, advancing the range it holds', async () => {
        const container = mountComposer();

        await type(container, 'both');
        field(container).setSelectionRange(0, 4);

        await openSheet(container);
        find(container, 'message-composer-format-**')!.click();
        await nextTick();
        find(container, 'message-composer-format-*')!.click();
        await nextTick();

        expect(field(container).value).toBe('***both***');
        expect(find(container, 'composer-attach-sheet')).not.toBeNull();
    });
});

describe('the attach sheet’s schedule rows', () => {
    it('offers the three quick presets and a custom time once there is text', async () => {
        const container = mountComposer({
            allowSchedule: true,
            timezone: 'UTC',
        });

        await type(container, 'later please');
        await openSheet(container);

        expect(
            [
                ...container.querySelectorAll<HTMLElement>(
                    '[data-test="sheet-send-later-preset"]',
                ),
            ].map((row) => row.dataset.preset),
        ).toEqual(['in-an-hour', 'tomorrow-morning', 'next-monday']);
        expect(find(container, 'sheet-send-later-custom')).not.toBeNull();
    });

    it('has nothing to schedule while the composer is empty', async () => {
        const container = mountComposer({
            allowSchedule: true,
            timezone: 'UTC',
        });

        await openSheet(container);

        expect(find(container, 'composer-sheet-schedule')).toBeNull();
    });

    it('leaves scheduling out of a composer that does not offer it', async () => {
        const container = mountComposer({ timezone: 'UTC' });

        await type(container, 'a thread reply');
        await openSheet(container);

        expect(find(container, 'composer-sheet-schedule')).toBeNull();
    });
});

describe('the desktop composer', () => {
    it('keeps its split pill and inline tools above the breakpoint', async () => {
        atViewportWidth(1280);

        const container = mountComposer({ allowSchedule: true });

        expect(find(container, 'composer-tools')).not.toBeNull();
        expect(find(container, 'message-composer-send')).not.toBeNull();
        expect(find(container, 'message-composer-schedule')).not.toBeNull();
        expect(find(container, 'composer-attach-sheet-toggle')).toBeNull();

        await type(container, 'still the same pill');

        // The mic keeps its own slot rather than taking the send button's.
        expect(find(container, 'message-composer-record')).not.toBeNull();
    });
});
