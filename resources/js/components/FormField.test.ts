import { describe, expect, it } from 'vitest';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import FormField from './FormField.vue';

/**
 * Renders `<FormField>` to an HTML string in the node test environment. `$t` is
 * stubbed to echo its key so any child relying on the global translate helper
 * resolves without the full app locale plumbing.
 *
 * The default slot mirrors a real call-site: it binds the scoped `id` onto an
 * `<input>` so the test can prove the label/control coupling is decided in one
 * place.
 */
async function renderField(
    props: Record<string, unknown>,
    slots: Record<string, (scope: { id: string }) => unknown> = {
        default: ({ id }) => h('input', { id, name: 'email' }),
    },
): Promise<string> {
    const app = createSSRApp({
        render: () => h(FormField, props, slots),
    });

    app.config.globalProperties.$t = (key: string) => key;

    return renderToString(app);
}

describe('FormField', () => {
    it('couples the label to the control by feeding one id to both', async () => {
        const html = await renderField({ id: 'email', label: 'Email address' });

        // The label points at the control...
        expect(html).toContain('for="email"');
        // ...and the control the slot rendered carries that exact id.
        expect(html).toContain('id="email"');
        expect(html).toContain('Email address');
    });

    it('renders the error message when one is present', async () => {
        const html = await renderField({
            id: 'email',
            label: 'Email address',
            error: 'The email field is required.',
        });

        expect(html).toContain('The email field is required.');
    });

    it('omits the error text when there is no error', async () => {
        const html = await renderField({ id: 'email', label: 'Email address' });

        expect(html).not.toContain('field is required');
        // The error slot stays hidden rather than reserving space.
        expect(html).toContain('style="display:none;"');
    });

    it('renders the optional hint line when provided', async () => {
        const html = await renderField({
            id: 'locale',
            label: 'Display language',
            hint: 'Dates and numbers follow your language.',
        });

        expect(html).toContain('Dates and numbers follow your language.');
    });

    it('renders a rich label through the label slot, overriding the prop', async () => {
        const html = await renderField(
            { id: 'confirmation-name', label: 'unused' },
            {
                default: ({ id }) => h('input', { id, name: 'name' }),
                label: () => [h('span', 'Type '), h('strong', '"Acme"')],
            },
        );

        expect(html).toContain('<strong>&quot;Acme&quot;</strong>');
        expect(html).not.toContain('unused');
    });

    it('applies labelClass to the label element', async () => {
        const html = await renderField({
            id: 'transfer-password',
            label: 'Password',
            labelClass: 'sr-only',
        });

        expect(html).toMatch(/<label[^>]*\bsr-only\b/);
    });

    it('renders trailing label content through the labelAction slot', async () => {
        const html = await renderField(
            { id: 'password', label: 'Password' },
            {
                default: ({ id }) => h('input', { id, name: 'password' }),
                labelAction: () =>
                    h('a', { href: '/forgot' }, 'Forgot password?'),
            },
        );

        expect(html).toContain('Forgot password?');
        expect(html).toContain('href="/forgot"');
    });

    it('emits the labelAction after the control so Tab reaches the control first', async () => {
        // Sequential focus follows the DOM, so a focusable label action drawn on
        // the label row must still be emitted after the control — otherwise Tab
        // out of the previous field lands on the link instead of this input.
        const html = await renderField(
            { id: 'password', label: 'Password' },
            {
                default: ({ id }) => h('input', { id, name: 'password' }),
                labelAction: () =>
                    h('a', { href: '/forgot' }, 'Forgot password?'),
            },
        );

        const labelAt = html.indexOf('for="password"');
        const controlAt = html.indexOf('name="password"');
        const actionAt = html.indexOf('href="/forgot"');

        expect(labelAt).toBeGreaterThanOrEqual(0);
        expect(controlAt).toBeGreaterThan(labelAt);
        expect(actionAt).toBeGreaterThan(controlAt);
    });
});
