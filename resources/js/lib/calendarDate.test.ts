import { CalendarDate } from '@internationalized/date';
import { describe, expect, it } from 'vitest';
import { toCalendarDate, toIsoDay } from './calendarDate';

describe('toCalendarDate', () => {
    it('parses an ISO day into a calendar date', () => {
        const parsed = toCalendarDate('2026-07-20');

        expect(parsed).toEqual(new CalendarDate(2026, 7, 20));
    });

    it('returns undefined for an empty, null, or malformed day', () => {
        expect(toCalendarDate('')).toBeUndefined();
        expect(toCalendarDate(null)).toBeUndefined();
        expect(toCalendarDate(undefined)).toBeUndefined();
        expect(toCalendarDate('20/07/2026')).toBeUndefined();
        expect(toCalendarDate('2026-13-01')).toBeUndefined();
    });
});

describe('toIsoDay', () => {
    it('serializes a calendar date as a zero-padded ISO day', () => {
        expect(toIsoDay(new CalendarDate(2026, 7, 5))).toBe('2026-07-05');
    });

    it('returns null when there is no value', () => {
        expect(toIsoDay(undefined)).toBeNull();
        expect(toIsoDay(null)).toBeNull();
    });

    it('round-trips an ISO day unchanged', () => {
        expect(toIsoDay(toCalendarDate('2026-01-09'))).toBe('2026-01-09');
    });
});
