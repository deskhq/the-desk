import { describe, expect, it } from 'vitest';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import InputError from './InputError.vue';

/**
 * `<InputError>` is used both through `<FormField>` and directly by the pages
 * that lay their own fields out, so its own contract is worth pinning: it draws
 * nothing at all when there is nothing to say, and what it does draw announces
 * itself.
 */
function renderError(message?: string): Promise<string> {
    return renderToString(
        createSSRApp({ render: () => h(InputError, { message }) }),
    );
}

describe('InputError', () => {
    it('announces the message when there is one', async () => {
        const html = await renderError('The email field is required.');

        expect(html).toContain('The email field is required.');
        expect(html).toContain('role="alert"');
    });

    it('renders nothing at all when there is no message', async () => {
        // Absent rather than hidden — a permanently-present empty node is
        // something assistive tech walks over for nothing (#883).
        const html = await renderError();

        expect(html).not.toContain('role="alert"');
        expect(html).not.toContain('display:none');
    });
});
