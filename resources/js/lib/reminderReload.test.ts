import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { REMINDER_PROPS, reminderReload } from '@/lib/reminderReload';

const SOURCE_ROOT = 'resources/js';

/** The module that owns the set; every other site imports it from here. */
const HOME = 'lib/reminderReload.ts';

/** Every source file under `resources/js`, tests excluded. */
function sourceFiles(directory = SOURCE_ROOT): string[] {
    return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
        const path = join(directory, entry.name);

        if (entry.isDirectory()) {
            return sourceFiles(path);
        }

        return /\.(ts|vue)$/.test(entry.name) &&
            !entry.name.endsWith('.test.ts')
            ? [path]
            : [];
    });
}

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
        const spelledOut = sourceFiles()
            .filter((path) => !path.endsWith(HOME))
            .filter((path) =>
                /'reminders',\s*'firedReminders'/.test(
                    readFileSync(path, 'utf8'),
                ),
            )
            .map((path) => path.slice(`${SOURCE_ROOT}/`.length));

        expect(spelledOut).toEqual([]);
    });
});
