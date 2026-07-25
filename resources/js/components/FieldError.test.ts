import { describe, expect, it } from 'vitest';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import FieldError from './FieldError.vue';

/**
 * The reserved error slot, used by `<FormField>` for every field and directly
 * by the few fields that cannot go through it. Its whole job is to occupy the
 * same space whether or not there is a message, so it is worth pinning
 * separately from the components that compose it.
 */
function renderFieldError(message?: string): Promise<string> {
    return renderToString(
        createSSRApp({ render: () => h(FieldError, { message }) }),
    );
}

describe('FieldError', () => {
    it('occupies the same slot whether or not there is a message', async () => {
        const clean = await renderFieldError();
        const failed = await renderFieldError('The email field is required.');

        for (const html of [clean, failed]) {
            expect(html).toContain('<div class="relative h-7">');
        }

        // Only the message differs between the two, never the slot.
        expect(clean).not.toContain('role="alert"');
        expect(failed).toContain('The email field is required.');
    });

    it('draws the message out of flow so it cannot grow the field', async () => {
        const html = await renderFieldError('The email field is required.');

        expect(html).toMatch(/class="[^"]*\babsolute\b/);
        expect(html).toMatch(/class="[^"]*\bpointer-events-none\b/);
        expect(html).toContain('role="alert"');
    });
});
