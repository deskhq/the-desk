import { describe, expect, it } from 'vitest';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import type { RenderedPresence } from '@/lib/presence';
import PresenceDot from './PresenceDot.vue';

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

    describe('as a corner badge', () => {
        it.each([
            { size: '18', dot: 'size-1.5', ring: 'ring-[1.5px]' },
            { size: '24', dot: 'size-2', ring: 'ring-2' },
            { size: '28', dot: 'size-2.5', ring: 'ring-2' },
            { size: '30', dot: 'size-2.5', ring: 'ring-2' },
            { size: '36', dot: 'size-2.5', ring: 'ring-2' },
            { size: '42', dot: 'size-2.75', ring: 'ring-2' },
            { size: '48', dot: 'size-3', ring: 'ring-[2.5px]' },
        ])(
            'owns the diameter and ring width for a $size px avatar',
            async ({ size, dot, ring }) => {
                const html = await render('active', { size });

                expect(html).toContain(dot);
                expect(html).toContain(ring);
            },
        );

        it('tucks itself inside the avatar bottom-right corner', async () => {
            const html = await render('active', { size: '24' });

            expect(html).toContain('absolute');
            expect(html).toContain('right-0');
            expect(html).toContain('bottom-0');
            expect(html).not.toContain('-right-');
            expect(html).not.toContain('-bottom-');
        });

        it('paints above the later siblings of an overlapping stack', async () => {
            expect(await render('active', { size: '24' })).toContain('z-10');
        });

        it('thins the away ring on the smallest avatar so the hollow centre survives', async () => {
            const html = await render('away', { size: '18' });

            expect(html).toContain('border-[1.5px]');
            expect(html).not.toContain('border-2');
        });

        it('keeps the standard away ring at every larger size', async () => {
            expect(await render('away', { size: '42' })).toContain('border-2');
        });

        it('still fills an away centre with the surface behind it', async () => {
            expect(
                await render('away', {
                    size: '18',
                    surfaceClass: 'bg-sidebar',
                }),
            ).toContain('bg-sidebar');
        });
    });

    it('stays an unpositioned inline dot when no badge size is given', async () => {
        const html = await render('active', { class: 'size-2' });

        expect(html).not.toContain('absolute');
        expect(html).not.toContain('z-10');
    });

    it('is invisible to assistive tech, which reads the surface own label', async () => {
        expect(await render('active')).toContain('aria-hidden="true"');
    });
});
