// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, h, nextTick, ref } from 'vue';
import { DatePicker } from '.';

/**
 * Mounts `<DatePicker>` for real — the reka-ui popover and calendar included —
 * so the tests exercise the seam a page sees: an ISO `YYYY-MM-DD` model in, a
 * locale-formatted trigger and an ISO day back out. `$t` echoes its key, which
 * is all the component's own copy needs.
 */
let app: App | null = null;

/**
 * The clock every test here runs on. Which month the calendar draws is derived
 * from a date, so a fixture that happens to sit in the *current* month asserts
 * nothing — it passes on a coincidence and fails at the next month boundary,
 * which is exactly how #1135 reached `develop`. Every fixture below is
 * deliberately in a different month from this one.
 */
const NOW = '2026-08-12T12:00:00.000Z';

type DatePickerProps = InstanceType<typeof DatePicker>['$props'];

function mount(props: DatePickerProps & Record<string, unknown>): HTMLElement {
    const host = document.createElement('div');
    document.body.appendChild(host);

    app = createApp({
        render: () => h(DatePicker, props),
    });
    app.config.globalProperties.$t = (key: string) => key;
    app.mount(host);

    return host;
}

function trigger(): HTMLElement {
    const element = document.querySelector<HTMLElement>(
        '[data-slot="date-picker-trigger"]',
    );

    if (element === null) {
        throw new Error('The date picker trigger was not rendered.');
    }

    return element;
}

/** Opens the popover and settles the calendar it portals into the document. */
async function openCalendar(): Promise<void> {
    trigger().click();
    await nextTick();
    await nextTick();
}

function dayCell(day: string): HTMLElement | null {
    return document.querySelector<HTMLElement>(
        `[data-reka-calendar-cell-trigger][data-value="${day}"]`,
    );
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date(NOW));
});

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
    vi.useRealTimers();
});

describe('DatePicker', () => {
    it('shows the placeholder while nothing is selected', () => {
        mount({
            modelValue: null,
            placeholder: 'Start date',
            fieldLabel: 'Start date',
        });

        expect(trigger().textContent).toContain('Start date');
    });

    it('shows the selected day formatted for the active locale', () => {
        mount({ modelValue: '2026-07-10', fieldLabel: 'Start date' });

        expect(trigger().textContent).toContain('Jul 10, 2026');
    });

    it('labels the trigger and flags an invalid value for assistive tech', () => {
        mount({
            modelValue: null,
            fieldLabel: 'Start date',
            invalid: true,
            'data-test': 'audit-export-range-start',
        });

        expect(trigger().getAttribute('aria-label')).toBe('Start date');
        expect(trigger().getAttribute('aria-invalid')).toBe('true');
        expect(trigger().getAttribute('data-test')).toBe(
            'audit-export-range-start',
        );
    });

    it('opens on the selected day rather than on today', async () => {
        mount({ modelValue: '2026-03-10', fieldLabel: 'Start date' });

        await openCalendar();

        expect(dayCell('2026-03-10')?.dataset.selected).toBe('true');
        expect(dayCell(NOW.slice(0, 10))).toBeNull();
    });

    it('opens on the current month while nothing is selected', async () => {
        mount({ modelValue: null, fieldLabel: 'Start date' });

        await openCalendar();

        expect(dayCell(NOW.slice(0, 10))).not.toBeNull();
    });

    it('emits the picked day as an ISO string', async () => {
        const selected = ref<string | null>('2026-07-10');

        mount({
            modelValue: selected.value,
            fieldLabel: 'Start date',
            'onUpdate:modelValue': (day: string | null) => {
                selected.value = day;
            },
        });

        await openCalendar();

        const cell = dayCell('2026-07-15');

        expect(cell).not.toBeNull();

        cell?.click();
        await nextTick();

        expect(selected.value).toBe('2026-07-15');
    });
});
