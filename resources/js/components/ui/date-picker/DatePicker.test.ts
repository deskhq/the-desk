// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
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

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
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

    it('emits the picked day as an ISO string', async () => {
        const selected = ref<string | null>('2026-07-10');

        mount({
            modelValue: selected.value,
            fieldLabel: 'Start date',
            'onUpdate:modelValue': (day: string | null) => {
                selected.value = day;
            },
        });

        trigger().click();
        await nextTick();
        await nextTick();

        const cell = document.querySelector<HTMLElement>(
            '[data-reka-calendar-cell-trigger][data-value="2026-07-15"]',
        );

        expect(cell).not.toBeNull();

        cell?.click();
        await nextTick();

        expect(selected.value).toBe('2026-07-15');
    });
});
