import { existsSync } from 'node:fs';
import { ESLint } from 'eslint';
import { parser as tsParser } from 'typescript-eslint';
import { beforeAll, describe, expect, it } from 'vitest';
import vueParser from 'vue-eslint-parser';
import config from '../eslint.config.js';

/**
 * Guards the file-size ratchet rather than a rule implementation: `max-lines`
 * is a built-in ESLint rule, and what needs protecting is its wiring — the
 * threshold applying to every ordinary file, and the grandfather list of
 * today's offenders being able to shrink but never grow.
 *
 * The list ossifies without this test: a file that gets split keeps its
 * exemption, and the next author regrows it for free. Asserting that every
 * exempt path still exists *and* still breaches the threshold makes an entry
 * that is no longer earned fail the suite until it is deleted (#957).
 */
const eslint = new ESLint();

/** A path that does not exist, standing in for a file someone adds tomorrow. */
const NEW_COMPONENT = 'resources/js/components/Probe.vue';
const NEW_COMPOSABLE = 'resources/js/composables/useProbe.ts';

type MaxLinesOptions = {
    max: number;
    skipBlankLines: boolean;
    skipComments: boolean;
};

/**
 * A rule entry is either a bare severity or a `[severity, ...options]` tuple;
 * `calculateConfigForFile` always hands back the tuple, a config block may use
 * either.
 */
function severityOf(entry: unknown): unknown {
    return Array.isArray(entry) ? entry[0] : entry;
}

/**
 * The paths the `files:` override block exempts, read back out of the resolved
 * flat config so the assertions follow the config instead of a second copy of
 * the list that could drift from it.
 */
const grandfathered = config
    .filter((entry) => {
        const severity = severityOf(entry.rules?.['max-lines']);

        return severity === 'off' || severity === 0;
    })
    .flatMap((entry) => (entry.files ?? []) as string[]);

/** Options the rule is configured with, resolved once for the whole file. */
let options: MaxLinesOptions;

/** Lint results for the grandfathered paths, with the exemption forced off. */
let breaches: Map<string, boolean>;

/** Grandfathered paths the probe could not parse, and so could not count. */
let unparsed: string[];

/**
 * Counts a file the way the project config does, but with nothing else loaded:
 * the same parsers, `max-lines` alone, and no plugin rule running over what are
 * by definition the largest files in the tree. Re-linting them under the full
 * config instead cost the whole vitest suite an order of magnitude in wall
 * clock, and starved its worker pool into unrelated failures.
 */
function countingProbe(maxLines: MaxLinesOptions): ESLint {
    return new ESLint({
        overrideConfigFile: true,
        overrideConfig: [
            {
                files: ['**/*.vue'],
                languageOptions: {
                    parser: vueParser,
                    // The SFCs here all use `<script setup lang="ts">`, which
                    // vue-eslint-parser hands to this inner parser; without it
                    // every one of them dies on the first type annotation, and
                    // a file that cannot be parsed reports no breach at all.
                    parserOptions: { parser: tsParser },
                },
            },
            { files: ['**/*.ts'], languageOptions: { parser: tsParser } },
            {
                languageOptions: { ecmaVersion: 'latest', sourceType: 'module' },
                rules: { 'max-lines': ['error', maxLines] },
            },
        ],
    });
}

/**
 * Resolving the flat config and counting the largest files in the tree costs a
 * second or two, and charging it to the first test would push that test past
 * vitest's per-test timeout under full-suite load (as it did in #804). Both
 * happen here instead, on a hook with a budget that fits.
 */
beforeAll(async () => {
    const resolved = await eslint.calculateConfigForFile(NEW_COMPONENT);

    options = (resolved.rules?.['max-lines'] as [unknown, MaxLinesOptions])[1];

    const probe = countingProbe(options);
    const existing = grandfathered.filter((path) => existsSync(path));
    // `lintFiles([])` falls back to linting the whole project, which would
    // turn an empty list into hundreds of unrelated failures.
    const results = existing.length > 0 ? await probe.lintFiles(existing) : [];

    breaches = new Map(
        results.map((result) => [
            result.filePath,
            result.messages.some((message) => message.ruleId === 'max-lines'),
        ]),
    );

    unparsed = results
        .filter((result) => result.messages.some((message) => message.fatal))
        .map((result) => result.filePath);
}, 60_000);

async function severityFor(filePath: string): Promise<unknown> {
    const resolved = await eslint.calculateConfigForFile(filePath);

    return (resolved.rules?.['max-lines'] as unknown[] | undefined)?.at(0);
}

describe('the max-lines policy', () => {
    it('caps a new component at the configured threshold', async () => {
        expect(await severityFor(NEW_COMPONENT)).toBe(2);
    });

    it('caps a new composable at the configured threshold', async () => {
        expect(await severityFor(NEW_COMPOSABLE)).toBe(2);
    });

    it('skips blank lines and comments, so documenting a declaration is free', () => {
        expect(options).toEqual({
            max: 400,
            skipBlankLines: true,
            skipComments: true,
        });
    });

    it('grandfathers today’s offenders', () => {
        expect(grandfathered.length).toBeGreaterThan(0);
    });

    it('exempts every grandfathered path by name, never by a glob that would also cover new files', () => {
        expect(grandfathered.filter((path) => /[*?[\]{}]/.test(path))).toEqual(
            [],
        );
    });

    it('exempts the files it lists', async () => {
        for (const path of grandfathered) {
            expect(await severityFor(path)).toBe(0);
        }
    });

    it('lists only files that still exist', () => {
        expect(grandfathered.filter((path) => !existsSync(path))).toEqual([]);
    });

    it('counts every grandfathered file, since one it cannot parse would read as under the cap', () => {
        expect(unparsed).toEqual([]);
    });

    it('lists only files that still breach the threshold, so the list can only shrink', () => {
        const undeserved = [...breaches.entries()]
            .filter(([, breached]) => !breached)
            .map(([filePath]) => filePath);

        expect(undeserved).toEqual([]);
    });
});
