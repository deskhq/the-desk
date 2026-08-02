import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { alertsOnMention, alertsOnUnread } from '@/lib/alerts';
import type { NotificationLevel } from '@/types';

/**
 * One case per row of the shared table (`tests/Fixtures/alert-cases.json`),
 * which `tests/Integration/Enums/AlertPredicateTest.php` reads to prove the
 * server's PHP and SQL forms. Reading the same file — rather than re-typing the
 * rows here — is what makes the two languages provably agree: a row added or
 * flipped on either side has to satisfy both suites.
 */
type AlertCase = {
    name: string;
    muted: boolean;
    level: NotificationLevel;
    alertsOnUnread: boolean;
    alertsOnMention: boolean;
};

const cases = JSON.parse(
    readFileSync(
        fileURLToPath(
            new URL(
                '../../../tests/Fixtures/alert-cases.json',
                import.meta.url,
            ),
        ),
        'utf8',
    ),
) as AlertCase[];

describe('the alert predicate', () => {
    it.each(cases)('$name', (testCase) => {
        const channel = {
            muted: testCase.muted,
            notificationLevel: testCase.level,
        };

        expect(alertsOnUnread(channel)).toBe(testCase.alertsOnUnread);
        expect(alertsOnMention(channel)).toBe(testCase.alertsOnMention);
    });

    it('answers each level both muted and unmuted, with no row repeated', () => {
        const mutedStatesPerLevel = new Map<NotificationLevel, Set<boolean>>();

        for (const testCase of cases) {
            const seen =
                mutedStatesPerLevel.get(testCase.level) ?? new Set<boolean>();
            seen.add(testCase.muted);
            mutedStatesPerLevel.set(testCase.level, seen);
        }

        // Which levels exist is the enum's question, so the PHP suite walks
        // `NotificationLevel::cases()` for that; this side only has to hold that
        // every level the table names is answered muted and unmuted, once each.
        for (const [, mutedStates] of mutedStatesPerLevel) {
            expect(mutedStates.size).toBe(2);
        }

        expect(mutedStatesPerLevel.size * 2).toBe(cases.length);
    });
});
