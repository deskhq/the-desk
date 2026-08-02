import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

/**
 * Test-only: the source walk the "spelled in exactly one place" tests share.
 *
 * Two suites enforce that rule — `reminderReload.test.ts` for the reminder pair
 * and `reloadProps.test.ts` for the sets beside it — and a set that has to be
 * named once deserves a scanner that is written once. Nothing the app ships
 * imports this, so `node:fs` never reaches a bundle.
 */

const SOURCE_ROOT = 'resources/js';

/**
 * Every source file under `resources/js`, with the test files and their
 * harnesses left out: an assertion naturally spells the set it is asserting on,
 * and so does a harness building the options a call is expected to carry.
 */
export function sourceFiles(directory = SOURCE_ROOT): string[] {
    return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
        const path = join(directory, entry.name);

        if (entry.isDirectory()) {
            return sourceFiles(path);
        }

        return /\.(ts|vue)$/.test(entry.name) &&
            !/\.(test|harness|doubles)\.ts$/.test(entry.name)
            ? [path]
            : [];
    });
}

/**
 * The source files outside `home` that spell `pattern` out, relative to
 * `resources/js` so a failure names the file to fix rather than a path prefix.
 */
export function filesSpelling(pattern: RegExp, home: string): string[] {
    return sourceFiles()
        .filter((path) => !path.endsWith(home))
        .filter((path) => pattern.test(readFileSync(path, 'utf8')))
        .map((path) => path.slice(`${SOURCE_ROOT}/`.length));
}
