import { describe, expect, it } from 'vitest';
import { errorContentFor } from './errorPages';

describe('errorContentFor', () => {
    it.each([403, 404, 419, 429, 500, 503])(
        'returns a bespoke heading and message for status %i',
        (status) => {
            const content = errorContentFor(status);

            expect(content.heading.length).toBeGreaterThan(0);
            expect(content.message.length).toBeGreaterThan(0);
        },
    );

    it('offers a workspace link on 404', () => {
        const content = errorContentFor(404);

        expect(content.actions).toEqual([
            {
                labelKey: 'Back to your workspace',
                kind: 'workspace',
                primary: true,
            },
        ]);
    });

    it('offers a reload affordance on 419 and 429', () => {
        expect(errorContentFor(419).actions[0].kind).toBe('reload');
        expect(errorContentFor(429).actions[0].kind).toBe('reload');
    });

    it('gives 503 no actions — maintenance resolves on its own', () => {
        expect(errorContentFor(503).actions).toEqual([]);
    });

    it('names the instance through the :app token, never our own brand', () => {
        expect(errorContentFor(503).message).toContain(':app');

        for (const status of [403, 404, 419, 429, 500, 503, 418]) {
            const { heading, message } = errorContentFor(status);

            expect(`${heading} ${message}`).not.toContain('The Desk');
        }
    });

    it('pairs a reload and a workspace fallback on 500', () => {
        expect(
            errorContentFor(500).actions.map((action) => action.kind),
        ).toEqual(['reload', 'workspace']);
    });

    it('falls back to a neutral variant for an unmapped status', () => {
        const content = errorContentFor(418);

        expect(content.heading).toBe('Something went wrong');
        expect(content.actions[0].kind).toBe('workspace');
    });
});
