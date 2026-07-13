import { describe, expect, it } from 'vitest';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import type { AttachmentData } from '@/types/attachments';
import MessageAttachments from './MessageAttachments.vue';

function image(overrides: Partial<AttachmentData> = {}): AttachmentData {
    return {
        id: `img-${Math.random()}`,
        filename: 'photo.png',
        mimeType: 'image/png',
        sizeBytes: 1_887_437, // 1.8 MiB
        width: 800,
        height: 600,
        isImage: true,
        url: 'https://desk.test/orig.png',
        thumbUrl: 'https://desk.test/thumb.png',
        ...overrides,
    };
}

function file(overrides: Partial<AttachmentData> = {}): AttachmentData {
    return {
        ...image(),
        id: `file-${Math.random()}`,
        isImage: false,
        filename: 'launch-checklist.pdf',
        mimeType: 'application/pdf',
        sizeBytes: 2_202_010, // 2.1 MiB
        thumbUrl: null,
        url: 'https://desk.test/doc.pdf',
        ...overrides,
    };
}

async function render(attachments: AttachmentData[]): Promise<string> {
    const app = createSSRApp({
        render: () =>
            h(MessageAttachments, {
                attachments,
                authorName: 'Jordan West',
                createdAt: '2024-01-01T11:02:00.000Z',
            }),
    });

    return renderToString(app);
}

function count(html: string, needle: string): number {
    return html.split(needle).length - 1;
}

describe('MessageAttachments', () => {
    it('renders a single image from its thumbnail with an open control and a download link to the original', async () => {
        const html = await render([image()]);

        expect(html).toContain('src="https://desk.test/thumb.png"');
        expect(html).toContain('Open photo.png');
        expect(html).toContain('href="https://desk.test/orig.png"');
        // The hover chip shows filename and size.
        expect(html).toContain('photo.png · 1.8 MB');
    });

    it('falls back to the original when no thumbnail was generated', async () => {
        const html = await render([image({ thumbUrl: null })]);

        expect(html).toContain('src="https://desk.test/orig.png"');
    });

    it('tiles multiple images in a grid', async () => {
        const html = await render([image(), image(), image()]);

        expect(html).toContain('data-test="attachment-grid"');
        expect(count(html, 'data-test="attachment-image"')).toBe(3);
        expect(html).not.toContain('data-test="attachment-overflow"');
    });

    it('caps the grid at four tiles and folds the rest into a "+N" overflow', async () => {
        const html = await render([
            image(),
            image(),
            image(),
            image(),
            image(),
            image(),
        ]);

        expect(count(html, 'data-test="attachment-image"')).toBe(4);
        expect(html).toContain('data-test="attachment-overflow"');
        expect(html).toContain('+2');
    });

    it('renders a non-image as a download card with its type and size', async () => {
        const html = await render([file()]);

        expect(html).toContain('data-test="attachment-file"');
        expect(html).toContain('launch-checklist.pdf');
        expect(html).toContain('PDF ·');
        expect(html).toContain('2.1 MB');
        expect(html).toContain('href="https://desk.test/doc.pdf"');
        expect(html).not.toContain('download only');
        // A pure file message renders no image grid.
        expect(html).not.toContain('data-test="attachment-grid"');
    });

    it('marks an svg card as download only', async () => {
        const html = await render([
            file({ filename: 'logo.svg', mimeType: 'image/svg+xml' }),
        ]);

        expect(html).toContain('SVG ·');
        expect(html).toContain('download only');
    });

    it('renders images and file cards together for a mixed message', async () => {
        const html = await render([image(), file()]);

        expect(html).toContain('data-test="attachment-image"');
        expect(html).toContain('data-test="attachment-file"');
    });
});
