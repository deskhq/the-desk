// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Covers the status dialog's "Clear after" half: the choices it offers, the
 * instant each one resolves to in the viewer's zone, and the custom day-and-time
 * picker — what it opens on, what it sends, and the guard that catches a time
 * already in the past.
 *
 * Written against the dialog as it stands so a split of it can be checked
 * against this suite. The field and the two writes are pinned in
 * `UserStatusDialog.form.test.ts`.
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
    choose,
    click,
    find,
    isDisabled,
    mountDialog,
    requests,
    resetDoubles,
    selectOptions,
    selectValue,
    type,
    unmountAll,
    viewer,
} from './UserStatusDialog.doubles';
import UserStatusDialog from './UserStatusDialog.vue';

/** A Thursday afternoon, off the 5-minute grid on purpose. */
const NOW = '2030-03-14T15:07:00.000Z';

/** The custom picker, as the dialog opens it on a chosen day and time. */
function customControls(host: HTMLElement) {
    return {
        date: (host.querySelector('[data-stub="calendar"]') as HTMLInputElement)
            .value,
        minDate: host
            .querySelector('[data-stub="calendar"]')
            ?.getAttribute('data-min'),
        hour: selectValue(host, 'status-expiry-hour'),
        minute: selectValue(host, 'status-expiry-minute'),
        period: [...host.querySelectorAll('[role="group"] button')].find(
            (button) => button.getAttribute('aria-pressed') === 'true',
        )?.textContent,
    };
}

/** Opens the dialog with an emoji picked, so Save is reachable. */
async function openSaveable(): Promise<{
    host: HTMLElement;
    open: (isOpen?: boolean) => Promise<void>;
}> {
    const { host, open } = mountDialog(UserStatusDialog);
    await open();
    await click(find(host, 'stub-pick-emoji'));

    return { host, open };
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date(NOW));
    resetDoubles();
});

afterEach(() => {
    unmountAll();
    vi.useRealTimers();
});

describe('the "Clear after" choices', () => {
    it('offers every choice, in order, and opens on "Don\'t clear"', async () => {
        const { host, open } = mountDialog(UserStatusDialog);
        await open();

        expect(selectOptions(host, 'status-expiry')).toEqual([
            'never',
            'thirty-minutes',
            'one-hour',
            'today',
            'this-week',
            'custom',
        ]);
        expect(selectValue(host, 'status-expiry')).toBe('never');
        expect(
            find(host, 'status-expiry')?.getAttribute('aria-labelledby'),
        ).toBe('status-expiry-label');
    });

    it('sends no expiry at all while "Don\'t clear" is chosen', async () => {
        const { host } = await openSaveable();
        await click(find(host, 'status-save'));

        expect(requests.puts[0].payload.expires_at).toBeNull();
    });

    it('resolves a relative choice against the clock', async () => {
        const { host } = await openSaveable();
        await choose(host, 'status-expiry', 'one-hour');
        await click(find(host, 'status-save'));

        expect(requests.puts[0].payload.expires_at).toBe(
            '2030-03-14T16:07:00.000Z',
        );
    });

    it("resolves an end-of-day choice against the viewer's own zone", async () => {
        viewer.timezone = 'Pacific/Kiritimati';

        const { host } = await openSaveable();
        await choose(host, 'status-expiry', 'today');
        await click(find(host, 'status-save'));

        // The viewer is already on the 15th at UTC+14, so their day ends at the
        // midnight opening the 16th — 10:00 UTC.
        expect(requests.puts[0].payload.expires_at).toBe(
            '2030-03-15T10:00:00.000Z',
        );
    });

    it('falls back to the browser zone when the viewer has none stored', async () => {
        viewer.timezone = null;

        const { host } = await openSaveable();
        await choose(host, 'status-expiry', 'today');
        await click(find(host, 'status-save'));

        const browserZone = Intl.DateTimeFormat().resolvedOptions().timeZone;

        // `hourCycle: 'h23'` rather than `hour12: false`, which renders midnight
        // as 24:00:00 under some ICU builds.
        expect(
            new Date(requests.puts[0].payload.expires_at as string)
                .toLocaleString('en-CA', {
                    timeZone: browserZone,
                    hourCycle: 'h23',
                })
                .slice(-8),
        ).toBe('00:00:00');
    });

    it('keeps the custom picker out of the way until it is asked for', async () => {
        const { host, open } = mountDialog(UserStatusDialog);
        await open();

        expect(find(host, 'status-custom-expiry')).toBeNull();

        await choose(host, 'status-expiry', 'custom');

        expect(find(host, 'status-custom-expiry')).not.toBeNull();

        await choose(host, 'status-expiry', 'today');

        expect(find(host, 'status-custom-expiry')).toBeNull();
    });
});

describe('the custom day-and-time picker', () => {
    it('opens a whole step ahead, snapped up onto the 5-minute grid', async () => {
        const { host } = await openSaveable();
        await choose(host, 'status-expiry', 'custom');

        expect(customControls(host)).toMatchObject({
            date: '2030-03-14',
            minDate: '2030-03-14',
            hour: '3',
            minute: '15',
            period: 'PM',
        });
        expect(find(host, 'status-expiry-past')).toBeNull();
        expect(isDisabled(find(host, 'status-save'))).toBe(false);
    });

    it('offers the twelve hours and the twelve grid minutes', async () => {
        const { host, open } = mountDialog(UserStatusDialog);
        await open();
        await choose(host, 'status-expiry', 'custom');

        expect(selectOptions(host, 'status-expiry-hour')).toEqual(
            Array.from({ length: 12 }, (_, index) => String(index + 1)),
        );
        expect(selectOptions(host, 'status-expiry-minute')).toEqual(
            Array.from({ length: 12 }, (_, index) => String(index * 5)),
        );
        expect(
            find(host, 'status-expiry-minute')
                ?.closest('[data-stub="select"]')
                ?.querySelector('[data-value="5"]')?.textContent,
        ).toBe('05');
    });

    it('names its controls for anyone who cannot see the row', async () => {
        const { host, open } = mountDialog(UserStatusDialog);
        await open();
        await choose(host, 'status-expiry', 'custom');

        expect(
            find(host, 'status-expiry-hour')?.getAttribute('aria-label'),
        ).toBe('Hour');
        expect(
            find(host, 'status-expiry-minute')?.getAttribute('aria-label'),
        ).toBe('Minute');
        expect(
            host
                .querySelector(
                    '[data-test="status-custom-expiry"] [role="group"]',
                )
                ?.getAttribute('aria-label'),
        ).toBe('AM or PM');
    });

    it("sends the instant its controls name, in the viewer's zone", async () => {
        const { host } = await openSaveable();
        await choose(host, 'status-expiry', 'custom');
        await type(host.querySelector('[data-stub="calendar"]'), '2030-03-20');
        await choose(host, 'status-expiry-hour', 5);
        await choose(host, 'status-expiry-minute', 30);
        await click(find(host, 'status-save'));

        expect(requests.puts[0].payload.expires_at).toBe(
            '2030-03-20T17:30:00.000Z',
        );
    });

    it('reads AM and PM off the pressed half of the toggle', async () => {
        const { host } = await openSaveable();
        await choose(host, 'status-expiry', 'custom');
        await type(host.querySelector('[data-stub="calendar"]'), '2030-03-20');

        const [am, pm] = [
            ...host.querySelectorAll(
                '[data-test="status-custom-expiry"] [role="group"] button',
            ),
        ];

        expect(pm.getAttribute('aria-pressed')).toBe('true');

        await click(am);

        expect(am.getAttribute('aria-pressed')).toBe('true');
        expect(pm.getAttribute('aria-pressed')).toBe('false');

        await click(find(host, 'status-save'));

        expect(requests.puts[0].payload.expires_at).toBe(
            '2030-03-20T03:15:00.000Z',
        );
    });

    it('says so and holds Save when the time named has already gone', async () => {
        const { host } = await openSaveable();
        await choose(host, 'status-expiry', 'custom');
        await type(host.querySelector('[data-stub="calendar"]'), '2030-03-13');

        expect(find(host, 'status-expiry-past')?.textContent?.trim()).toBe(
            'Pick a time in the future.',
        );
        expect(isDisabled(find(host, 'status-save'))).toBe(true);

        await click(find(host, 'status-save'));

        expect(requests.puts).toHaveLength(0);
    });

    it('opens on an existing expiry, pointed at it rather than nudged past it', async () => {
        viewer.status = {
            emoji: '📅',
            text: 'In a meeting',
            expiresAt: '2030-03-20T09:23:00.000Z',
        };

        const { host, open } = mountDialog(UserStatusDialog);
        await open();

        expect(selectValue(host, 'status-expiry')).toBe('custom');
        expect(customControls(host)).toMatchObject({
            date: '2030-03-20',
            hour: '9',
            minute: '20',
            period: 'AM',
        });
    });

    it("holds the calendar to the viewer's own today", async () => {
        viewer.timezone = 'Pacific/Kiritimati';

        const { host, open } = mountDialog(UserStatusDialog);
        await open();
        await choose(host, 'status-expiry', 'custom');

        expect(customControls(host).minDate).toBe('2030-03-15');
    });
});
