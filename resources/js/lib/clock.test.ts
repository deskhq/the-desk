import { afterEach, describe, expect, it } from 'vitest';
import {
    hourCycleFor,
    prefersTwelveHour,
    setTimeFormat,
    timeFormat,
} from './clock';

afterEach(() => {
    setTimeFormat('auto');
});

describe('timeFormat', () => {
    it('starts on auto, so the language decides the clock style', () => {
        expect(timeFormat()).toBe('auto');
    });

    it('reads back the preference it was set to', () => {
        setTimeFormat('24h');

        expect(timeFormat()).toBe('24h');
    });
});

describe('hourCycleFor', () => {
    it('leaves the hour cycle to the locale under auto', () => {
        expect(hourCycleFor('auto')).toBeUndefined();
    });

    it('pins a 12-hour cycle that renders midnight as 12, not 0', () => {
        expect(hourCycleFor('12h')).toBe('h12');
    });

    it('pins a 24-hour cycle that renders midnight as 00, not 24', () => {
        expect(hourCycleFor('24h')).toBe('h23');
    });

    it('falls back to the stored preference', () => {
        setTimeFormat('12h');

        expect(hourCycleFor()).toBe('h12');
    });
});

describe('prefersTwelveHour', () => {
    it('resolves auto against the locale', () => {
        expect(prefersTwelveHour('en', 'auto')).toBe(true);
        expect(prefersTwelveHour('fr', 'auto')).toBe(false);
    });

    it('overrides the locale when a style is chosen', () => {
        expect(prefersTwelveHour('fr', '12h')).toBe(true);
        expect(prefersTwelveHour('en', '24h')).toBe(false);
    });

    it('falls back to the stored preference', () => {
        setTimeFormat('12h');

        expect(prefersTwelveHour('fr')).toBe(true);
    });
});
