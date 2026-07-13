import { describe, expect, it } from 'vitest';
import {
    fileTypeLabel,
    imageGridColumns,
    imageGridTiles,
    partitionAttachments,
    singleImageSize,
} from '@/lib/attachmentLayout';
import type { AttachmentData } from '@/types/attachments';

function attachment(overrides: Partial<AttachmentData> = {}): AttachmentData {
    return {
        id: 'a1',
        filename: 'file.bin',
        mimeType: 'application/octet-stream',
        sizeBytes: 1024,
        width: null,
        height: null,
        isImage: false,
        url: 'https://example.test/a1/download',
        thumbUrl: null,
        ...overrides,
    };
}

describe('partitionAttachments', () => {
    it('splits images from files, preserving order', () => {
        const image = attachment({ id: 'img', isImage: true });
        const svg = attachment({ id: 'svg', mimeType: 'image/svg+xml' });
        const pdf = attachment({ id: 'pdf', mimeType: 'application/pdf' });

        const { images, files } = partitionAttachments([image, svg, pdf]);

        expect(images.map((a) => a.id)).toEqual(['img']);
        expect(files.map((a) => a.id)).toEqual(['svg', 'pdf']);
    });
});

describe('singleImageSize', () => {
    it('scales a large image down to fit the box, preserving ratio', () => {
        expect(singleImageSize(2400, 1500)).toEqual({
            width: 380,
            height: 238,
        });
    });

    it('caps by height when the image is tall', () => {
        expect(singleImageSize(400, 800)).toEqual({ width: 120, height: 240 });
    });

    it('never upscales an image smaller than the box', () => {
        expect(singleImageSize(120, 90)).toEqual({ width: 120, height: 90 });
    });

    it('falls back to the full box when dimensions are unknown', () => {
        expect(singleImageSize(null, 200)).toEqual({ width: 380, height: 240 });
        expect(singleImageSize(200, null)).toEqual({ width: 380, height: 240 });
    });
});

describe('imageGridColumns', () => {
    it('tiles three across but pairs everything else', () => {
        expect(imageGridColumns(2)).toBe(2);
        expect(imageGridColumns(3)).toBe(3);
        expect(imageGridColumns(4)).toBe(2);
        expect(imageGridColumns(5)).toBe(2);
    });
});

describe('imageGridTiles', () => {
    it('shows every image with no overflow at four or fewer', () => {
        const tiles = imageGridTiles([
            attachment({ id: '1', isImage: true }),
            attachment({ id: '2', isImage: true }),
        ]);

        expect(tiles).toHaveLength(2);
        expect(tiles.every((tile) => tile.overflow === 0)).toBe(true);
        expect(tiles.map((tile) => tile.index)).toEqual([0, 1]);
    });

    it('folds the extra images into a "+N" overflow on the last tile', () => {
        const images = Array.from({ length: 6 }, (_, index) =>
            attachment({ id: `${index}`, isImage: true }),
        );

        const tiles = imageGridTiles(images);

        expect(tiles).toHaveLength(4);
        expect(tiles[3].overflow).toBe(2);
        expect(tiles.slice(0, 3).every((tile) => tile.overflow === 0)).toBe(
            true,
        );
    });
});

describe('fileTypeLabel', () => {
    it('upper-cases the filename extension', () => {
        expect(fileTypeLabel('launch-checklist.pdf', 'application/pdf')).toBe(
            'PDF',
        );
        expect(fileTypeLabel('logo.svg', 'image/svg+xml')).toBe('SVG');
    });

    it('falls back to the MIME subtype when there is no usable extension', () => {
        expect(fileTypeLabel('README', 'text/plain')).toBe('PLAIN');
        expect(fileTypeLabel('archive.superlongext', 'application/zip')).toBe(
            'ZIP',
        );
    });

    it('falls back to FILE when the MIME has no subtype', () => {
        expect(fileTypeLabel('mystery', 'application')).toBe('FILE');
    });
});
