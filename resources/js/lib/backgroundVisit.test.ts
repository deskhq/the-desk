import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { backgroundVisit } from '@/lib/backgroundVisit';

const SOURCE_ROOT = 'resources/js';

/**
 * The `router.reload` call sites that are deliberately foreground, each with the
 * reason it is the user's own request rather than a background refresh. Anything
 * not listed here must spread {@see backgroundVisit}, so a new reload forces a
 * conscious choice instead of silently inheriting interrupt semantics (#586).
 */
const FOREGROUND_RELOADS: { file: string; call: string; reason: string }[] = [
    {
        file: 'composables/useTeamSwitch.ts',
        call: 'router.reload()',
        reason: 'the tail of the user-initiated team switch itself',
    },
    {
        file: 'components/PasskeyManagement.vue',
        call: "router.reload({ only: ['passkeys'] })",
        reason: 'refreshes the list the user just registered a passkey into',
    },
    {
        file: 'pages/channels/Show.vue',
        call: "router.reload({ only: ['pins', 'pinCount'] })",
        reason: 'the user opened the pins popover and is waiting on it',
    },
];

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

/** The full source text of each `router.reload(...)` call in `source`. */
function reloadCalls(source: string): string[] {
    const calls: string[] = [];
    const marker = 'router.reload(';

    for (
        let start = source.indexOf(marker);
        start !== -1;
        start = source.indexOf(marker, start + 1)
    ) {
        let depth = 0;

        for (
            let index = start + marker.length - 1;
            index < source.length;
            index++
        ) {
            depth +=
                Number(source[index] === '(') - Number(source[index] === ')');

            if (depth === 0) {
                calls.push(source.slice(start, index + 1));
                break;
            }
        }
    }

    return calls;
}

/** Collapse the whitespace a formatter may have wrapped a call across. */
function normalize(call: string): string {
    return call.replace(/\s+/g, ' ').replace(/,(?= })/g, '');
}

describe('backgroundVisit', () => {
    it('takes the request out of the sync queue and off the address bar', () => {
        // Both halves matter and are asserted at every call site that spreads
        // this: `async` so the request cannot interrupt an in-flight visit, and
        // `preserveUrl` so its redirect-follow cannot rewrite the URL underneath
        // the user.
        expect(backgroundVisit).toEqual({ async: true, preserveUrl: true });
    });

    it('is spread by every router.reload that is not an explicit foreground one', () => {
        const allowed = new Set(
            FOREGROUND_RELOADS.map(
                (entry) => `${entry.file} ${normalize(entry.call)}`,
            ),
        );

        const unguarded = sourceFiles().flatMap((path) => {
            const file = path.slice(`${SOURCE_ROOT}/`.length);

            return reloadCalls(readFileSync(path, 'utf8'))
                .filter((call) => !call.includes('backgroundVisit'))
                .map((call) => `${file} ${normalize(call)}`)
                .filter((entry) => !allowed.has(entry));
        });

        expect(unguarded).toEqual([]);
    });
});
