// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Covers the status dialog's field, its quick picks and the two writes it
 * makes: what the emoji square previews, what a preset fills in, the request
 * Save fires, and the one Clear fires.
 *
 * Written against the dialog as it stands so a split of it can be checked
 * against this suite. A child that forgets to re-emit, or fires a request under
 * a different shape, is exactly what a pure move risks — and it is what these
 * assertions are here to catch. The expiry half is pinned in
 * `UserStatusDialog.expiry.test.ts`.
 */
vi.mock('@inertiajs/vue3', async () => {
    const { inertiaDouble } = await import('./UserStatusDialog.doubles');

    return inertiaDouble();
});

vi.mock('@lucide/vue', async () => {
    const { lucideDouble } = await import('./UserStatusDialog.doubles');

    return lucideDouble();
});

vi.mock('@/components/EmojiPickerPopover.vue', async () => {
    const { emojiPickerDouble } = await import('./UserStatusDialog.doubles');

    return emojiPickerDouble();
});

vi.mock('@/components/UserStatusEmoji.vue', async () => {
    const { statusEmojiDouble } = await import('./UserStatusDialog.doubles');

    return statusEmojiDouble();
});

vi.mock('@/components/ui/button', async () => {
    const { passthrough } = await import('./UserStatusDialog.doubles');

    return { Button: passthrough('button') };
});

vi.mock('@/components/ui/calendar', async () => {
    const { calendarDouble } = await import('./UserStatusDialog.doubles');

    return calendarDouble();
});

vi.mock('@/components/ui/dialog', async () => {
    const { dialogDouble } = await import('./UserStatusDialog.doubles');

    return dialogDouble();
});

vi.mock('@/components/ui/input', async () => {
    const { inputDouble } = await import('./UserStatusDialog.doubles');

    return inputDouble();
});

vi.mock('@/components/ui/select', async () => {
    const { selectDouble } = await import('./UserStatusDialog.doubles');

    return selectDouble();
});

vi.mock('@/composables/useToast', async () => {
    const { toastDouble } = await import('./UserStatusDialog.doubles');

    return toastDouble();
});

import {
    destroy as destroyStatus,
    update as updateStatus,
} from '@/actions/App/Http/Controllers/Settings/StatusController';
import {
    click,
    find,
    findAll,
    isDisabled,
    mountDialog,
    PICKED_EMOJI,
    requests,
    resetDoubles,
    toasted,
    type,
    unmountAll,
    viewer,
} from './UserStatusDialog.doubles';
import UserStatusDialog from './UserStatusDialog.vue';

/** The status the viewer already has, for the editing branch. */
function existingStatus(
    overrides: Partial<App.Data.UserStatusData> = {},
): App.Data.UserStatusData {
    return { emoji: '📅', text: 'In a meeting', expiresAt: null, ...overrides };
}

beforeEach(() => {
    resetDoubles();
});

afterEach(() => {
    unmountAll();
});

describe('the dialog shell', () => {
    it('stays out of the DOM until it is opened', async () => {
        const { host, open } = mountDialog(UserStatusDialog);

        expect(find(host, 'status-dialog')).toBeNull();

        await open();

        expect(find(host, 'status-dialog')).not.toBeNull();
    });

    it('titles itself by whether there is a status to edit', async () => {
        const first = mountDialog(UserStatusDialog);
        await first.open();

        expect(find(first.host, 'status-dialog')?.textContent).toContain(
            'Set a status',
        );

        viewer.status = existingStatus();

        const second = mountDialog(UserStatusDialog);
        await second.open();

        expect(find(second.host, 'status-dialog')?.textContent).toContain(
            'Edit status',
        );
    });

    it('describes itself for screen readers', async () => {
        const { host, open } = mountDialog(UserStatusDialog);
        await open();

        expect(find(host, 'status-dialog')?.textContent).toContain(
            'Pick an emoji and a short message your teammates will see beside your name.',
        );
    });

    it('closes on Cancel without writing anything', async () => {
        const { host, open, emitted } = mountDialog(UserStatusDialog);
        await open();

        const cancel = [...host.querySelectorAll('button')].find(
            (button) => button.textContent?.trim() === 'Cancel',
        );

        await click(cancel ?? null);

        expect(emitted).toEqual([false]);
        expect(requests.puts).toHaveLength(0);
        expect(requests.deletes).toHaveLength(0);
    });
});

describe('the status field', () => {
    it('counts the characters left against the column limit', async () => {
        const { host, open } = mountDialog(UserStatusDialog);
        await open();

        expect(find(host, 'status-text-counter')?.textContent).toBe('0/100');
        expect(find(host, 'status-text-input')?.getAttribute('maxlength')).toBe(
            '100',
        );

        await type(find(host, 'status-text-input'), 'Heads down');

        expect(find(host, 'status-text-counter')?.textContent).toBe('10/100');
    });

    it('previews nothing until there is something to save', async () => {
        const { host, open } = mountDialog(UserStatusDialog);
        await open();

        expect(find(host, 'user-status-emoji')).toBeNull();
        expect(isDisabled(find(host, 'status-save'))).toBe(true);
    });

    it('previews the emoji the picker hands back', async () => {
        const { host, open } = mountDialog(UserStatusDialog);
        await open();
        await click(find(host, 'stub-pick-emoji'));

        expect(find(host, 'user-status-emoji')?.textContent).toBe(PICKED_EMOJI);
        expect(isDisabled(find(host, 'status-save'))).toBe(false);
    });

    it('previews the default emoji once there is text but no pick', async () => {
        const { host, open } = mountDialog(UserStatusDialog);
        await open();
        await type(find(host, 'status-text-input'), 'Heads down');

        expect(find(host, 'user-status-emoji')?.textContent).toBe('💬');
    });

    it('opens on the status already set', async () => {
        viewer.status = existingStatus();

        const { host, open } = mountDialog(UserStatusDialog);
        await open();

        expect(
            (find(host, 'status-text-input') as HTMLInputElement).value,
        ).toBe('In a meeting');
        expect(find(host, 'user-status-emoji')?.textContent).toBe('📅');
    });

    it('drops a half-edited draft when it is reopened', async () => {
        const { host, open } = mountDialog(UserStatusDialog);
        await open();
        await type(find(host, 'status-text-input'), 'Heads down');
        await open(false);
        await open();

        expect(
            (find(host, 'status-text-input') as HTMLInputElement).value,
        ).toBe('');
    });
});

describe('the quick picks', () => {
    it('offers four, each with its default expiry, while nothing is set', async () => {
        const { host, open } = mountDialog(UserStatusDialog);
        await open();

        const presets = findAll(host, 'status-preset');

        expect(presets.map((preset) => preset.dataset.preset)).toEqual([
            'meeting',
            'remote',
            'sick',
            'commuting',
        ]);
        expect(
            presets.map((preset) =>
                [...preset.querySelectorAll('span')].map(
                    (part) => part.textContent,
                ),
            ),
        ).toEqual([
            ['📅', 'In a meeting', '1 hour'],
            ['🏠', 'Working remotely', 'Today'],
            ['🤒', 'Out sick', 'Today'],
            ['🚌', 'Commuting', '30 minutes'],
        ]);
    });

    it('names the group by the heading above it rather than by a list role', async () => {
        const { host, open } = mountDialog(UserStatusDialog);
        await open();

        const group = host.querySelector('[role="group"]');

        expect(group?.getAttribute('aria-labelledby')).toBe(
            'status-presets-label',
        );
        expect(host.querySelector('#status-presets-label')?.textContent).toBe(
            'Quick picks',
        );
    });

    it('is withheld once a status is already set', async () => {
        viewer.status = existingStatus();

        const { host, open } = mountDialog(UserStatusDialog);
        await open();

        expect(findAll(host, 'status-preset')).toHaveLength(0);
    });

    it('fills the emoji, the text and the expiry in one tap', async () => {
        const { host, open } = mountDialog(UserStatusDialog);
        await open();
        await click(findAll(host, 'status-preset')[0]);

        expect(
            (find(host, 'status-text-input') as HTMLInputElement).value,
        ).toBe('In a meeting');
        expect(find(host, 'user-status-emoji')?.textContent).toBe('📅');
        expect(
            find(host, 'status-expiry')
                ?.closest('[data-stub="select"]')
                ?.getAttribute('data-value'),
        ).toBe('one-hour');
    });
});

describe('saving', () => {
    it('sends the emoji, the trimmed text and the expiry', async () => {
        const { host, open } = mountDialog(UserStatusDialog);
        await open();
        await click(find(host, 'stub-pick-emoji'));
        await type(find(host, 'status-text-input'), '  Heads down  ');
        await click(find(host, 'status-save'));

        expect(requests.puts).toHaveLength(1);
        expect(requests.puts[0].url).toBe(updateStatus().url);
        expect(requests.puts[0].payload).toEqual({
            emoji: PICKED_EMOJI,
            text: 'Heads down',
            expires_at: null,
        });
        expect(requests.puts[0].options.preserveScroll).toBe(true);
    });

    it('saves text with no chosen emoji under the neutral default', async () => {
        const { host, open } = mountDialog(UserStatusDialog);
        await open();
        await type(find(host, 'status-text-input'), 'Heads down');
        await click(find(host, 'status-save'));

        expect(requests.puts[0].payload.emoji).toBe('💬');
    });

    it('sends no text at all when only an emoji was picked', async () => {
        const { host, open } = mountDialog(UserStatusDialog);
        await open();
        await click(find(host, 'stub-pick-emoji'));
        await click(find(host, 'status-save'));

        expect(requests.puts[0].payload.text).toBeNull();
    });

    it('holds the button while the write is in flight, and closes on success', async () => {
        const { host, open, emitted } = mountDialog(UserStatusDialog);
        await open();
        await click(find(host, 'stub-pick-emoji'));
        await click(find(host, 'status-save'));

        expect(isDisabled(find(host, 'status-save'))).toBe(true);

        requests.puts[0].options.onSuccess?.();
        requests.puts[0].options.onFinish?.();

        expect(emitted).toEqual([false]);
    });

    it('says so and stays open when the write fails', async () => {
        const { host, open, emitted } = mountDialog(UserStatusDialog);
        await open();
        await click(find(host, 'stub-pick-emoji'));
        await click(find(host, 'status-save'));

        requests.puts[0].options.onError?.();
        requests.puts[0].options.onFinish?.();

        expect(toasted.errors).toEqual(['Could not save your status']);
        expect(emitted).toEqual([]);
    });

    it('fires nothing while there is nothing to save', async () => {
        const { host, open } = mountDialog(UserStatusDialog);
        await open();
        await click(find(host, 'status-save'));

        expect(requests.puts).toHaveLength(0);
    });
});

describe('clearing', () => {
    it('is offered only once a status is set', async () => {
        const { host, open } = mountDialog(UserStatusDialog);
        await open();

        expect(find(host, 'status-clear')).toBeNull();

        viewer.status = existingStatus();

        const editing = mountDialog(UserStatusDialog);
        await editing.open();

        expect(find(editing.host, 'status-clear')).not.toBeNull();
    });

    it('deletes the status and closes on success', async () => {
        viewer.status = existingStatus();

        const { host, open, emitted } = mountDialog(UserStatusDialog);
        await open();
        await click(find(host, 'status-clear'));

        expect(requests.deletes).toHaveLength(1);
        expect(requests.deletes[0].url).toBe(destroyStatus().url);
        expect(requests.deletes[0].options.preserveScroll).toBe(true);
        expect(isDisabled(find(host, 'status-clear'))).toBe(true);

        requests.deletes[0].options.onSuccess?.();
        requests.deletes[0].options.onFinish?.();

        expect(emitted).toEqual([false]);
    });

    it('says so and stays open when the delete fails', async () => {
        viewer.status = existingStatus();

        const { host, open, emitted } = mountDialog(UserStatusDialog);
        await open();
        await click(find(host, 'status-clear'));

        requests.deletes[0].options.onError?.();
        requests.deletes[0].options.onFinish?.();

        expect(toasted.errors).toEqual(['Could not clear your status']);
        expect(emitted).toEqual([]);
    });
});
