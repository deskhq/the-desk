import { describe, expect, it } from 'vitest';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import PresenceDot from './PresenceDot.vue';
import type { RenderedPresence } from '@/lib/presence';

function render(
    presence: RenderedPresence,
    props: Record<string, unknown> = {},
): Promise<string> {
    return renderToString(
        createSSRApp({
            render: () => h(PresenceDot, { presence, ...props }),
        }),
    );
}

describe('PresenceDot', () => {
    it('fills the dot for someone active', async () => {
        const html = await render('active');

        expect(html).toContain('bg-emerald-500');
        expect(html).not.toContain('border-muted-foreground');
    });

    it('hollows the dot for someone away, keeping its footprint', async () => {
        const html = await render('away');

        expect(html).toContain('border-muted-foreground');
        expect(html).not.toContain('bg-emerald-500');
    });

    it('fills an away dot with the surface behind it so the avatar cannot show through', async () => {
        const html = await render('away', { surfaceClass: 'bg-sidebar' });

        expect(html).toContain('bg-sidebar');
    });

    it('ignores the surface fill for any state that is not away', async () => {
        const html = await render('active', { surfaceClass: 'bg-sidebar' });

        expect(html).not.toContain('bg-sidebar');
    });

    it('mutes the dot for someone offline', async () => {
        const html = await render('offline');

        expect(html).toContain('bg-muted-foreground/50');
    });

    it('carries the caller geometry so each surface keeps its own size and ring', async () => {
        const html = await render('active', {
            class: 'absolute size-2.5 ring-2 ring-card',
        });

        expect(html).toContain('size-2.5');
        expect(html).toContain('ring-card');
    });

    it('records the state for tests and styling hooks', async () => {
        expect(await render('away')).toContain('data-presence="away"');
    });

    it('is invisible to assistive tech, which reads the surface own label', async () => {
        expect(await render('active')).toContain('aria-hidden="true"');
    });
});
