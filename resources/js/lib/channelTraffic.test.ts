import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { isChannelTraffic } from '@/lib/channelTraffic';

/**
 * One case per row of the shared table
 * (`tests/Fixtures/channel-traffic-cases.json`), which
 * `tests/Integration/Models/ChannelTrafficPredicateTest.php` reads to prove the
 * server scope and its in-memory twin. Reading the same file — rather than
 * re-typing the rows here — is what makes the two languages provably agree: a
 * row added or flipped on either side has to satisfy both suites.
 */
type ChannelTrafficCase = {
    name: string;
    threadRootId: string | null;
    sentToChannel: boolean;
    isChannelTraffic: boolean;
};

const cases = JSON.parse(
    readFileSync(
        fileURLToPath(
            new URL(
                '../../../tests/Fixtures/channel-traffic-cases.json',
                import.meta.url,
            ),
        ),
        'utf8',
    ),
) as ChannelTrafficCase[];

describe('isChannelTraffic', () => {
    it.each(cases)('$name', (testCase) => {
        expect(
            isChannelTraffic({
                threadRootId: testCase.threadRootId,
                sentToChannel: testCase.sentToChannel,
            }),
        ).toBe(testCase.isChannelTraffic);
    });

    it('covers every combination of the two facts the rule reads', () => {
        const combinations = new Set(
            cases.map(
                (testCase) =>
                    `${testCase.threadRootId}/${testCase.sentToChannel}`,
            ),
        );

        expect(combinations.size).toBe(4);
    });
});
