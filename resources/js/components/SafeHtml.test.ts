// @vitest-environment jsdom
// DOMPurify needs a DOM; jsdom gives this file a `window` so the component
// sanitizes exactly as it does in the browser.
import { afterEach, describe, expect, it } from 'vitest';
import type { App } from 'vue';
import { createApp, h, nextTick, ref } from 'vue';
import type { SanitizeVariant } from '@/lib/sanitizeHtml';
import SafeHtml from './SafeHtml.vue';

let app: App | null = null;

function mount(props: {
    html: string;
    variant: SanitizeVariant;
    as?: 'span' | 'div';
    class?: string;
    'data-test'?: string;
}) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    app = createApp({ render: () => h(SafeHtml, props) });
    app.mount(host);

    return host;
}

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

describe('SafeHtml', () => {
    it('renders allowlisted markup as real elements', () => {
        const host = mount({
            html: '<strong>bold</strong> and <em>italic</em>',
            variant: 'messageBody',
        });

        expect(host.querySelector('strong')?.textContent).toBe('bold');
        expect(host.querySelector('em')?.textContent).toBe('italic');
    });

    it('strips markup the variant does not allow', () => {
        const host = mount({
            html: '<img src=x onerror="alert(1)"><strong>kept</strong>',
            variant: 'searchSnippet',
        });

        expect(host.querySelector('img')).toBeNull();
        expect(host.querySelector('strong')).toBeNull();
        expect(host.textContent).toBe('kept');
    });

    it('sanitizes under the variant it is given, not a shared allowlist', () => {
        const snippet = mount({
            html: '<mark>hit</mark>',
            variant: 'searchSnippet',
        });

        expect(snippet.querySelector('mark')).not.toBeNull();

        app?.unmount();
        app = null;
        document.body.innerHTML = '';

        const body = mount({
            html: '<mark>hit</mark>',
            variant: 'messageBody',
        });

        expect(body.querySelector('mark')).toBeNull();
        expect(body.textContent).toBe('hit');
    });

    it('keeps a QR code SVG intact under the qrCode variant', () => {
        const host = mount({
            html:
                '<svg viewBox="0 0 1 1"><rect x="0" y="0" fill="#fff"/>' +
                '<script>alert(1)</script></svg>',
            variant: 'qrCode',
            as: 'div',
        });

        expect(host.querySelector('svg')).not.toBeNull();
        expect(host.querySelector('rect')?.getAttribute('fill')).toBe('#fff');
        expect(host.querySelector('script')).toBeNull();
    });

    it('renders a span by default and the requested element otherwise', () => {
        const span = mount({ html: 'hi', variant: 'messageBody' });

        expect(span.firstElementChild?.tagName).toBe('SPAN');

        app?.unmount();
        app = null;
        document.body.innerHTML = '';

        const div = mount({ html: 'hi', variant: 'messageBody', as: 'div' });

        expect(div.firstElementChild?.tagName).toBe('DIV');
    });

    it('passes attributes through to the rendered element', () => {
        const host = mount({
            html: 'hi',
            variant: 'messageBody',
            class: 'text-sm',
            'data-test': 'body',
        });

        const element = host.firstElementChild;

        expect(element?.getAttribute('class')).toBe('text-sm');
        expect(element?.getAttribute('data-test')).toBe('body');
    });

    it('re-sanitizes when the html changes', async () => {
        const html = ref('<strong>first</strong>');
        const host = document.createElement('div');
        document.body.appendChild(host);

        app = createApp({
            render: () =>
                h(SafeHtml, { html: html.value, variant: 'messageBody' }),
        });
        app.mount(host);

        expect(host.querySelector('strong')?.textContent).toBe('first');

        html.value = '<script>alert(1)</script><em>second</em>';
        await nextTick();

        expect(host.querySelector('script')).toBeNull();
        expect(host.querySelector('em')?.textContent).toBe('second');
    });
});
