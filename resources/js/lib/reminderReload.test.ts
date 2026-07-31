import { describe, expect, it } from 'vitest';
import { REMINDER_PROPS, reminderReload } from '@/lib/reminderReload';
import { filesSpelling } from '@/lib/sourceScan.harness';

/** The module that owns the set; every other site imports it from here. */
const HOME = 'lib/reminderReload.ts';

describe('reminderReload', () => {
    it('names both reminder props, since a mutation moves rows between them', () => {
        // A reminder coming due leaves `reminders` and appears in
        // `firedReminders`; clearing a fired one leaves the second. Refreshing
        // one without the other leaves the list, the rail's dot and the nudges
        // disagreeing about the same row.
        expect(REMINDER_PROPS).toEqual(['reminders', 'firedReminders']);
    });

    it('leaves the page where it is, so a mutation never moves the reader', () => {
        expect(reminderReload).toEqual({
            preserveScroll: true,
            preserveState: true,
            only: REMINDER_PROPS,
        });
    });

    it('is the only place the set is spelled out', () => {
        // Six copies across five files is what this replaces: adding a reminder
        // field meant finding all six (#1093).
        expect(filesSpelling(/'reminders',\s*'firedReminders'/, HOME)).toEqual(
            [],
        );
    });
});
