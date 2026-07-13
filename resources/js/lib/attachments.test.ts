import { describe, expect, it } from 'vitest';
import { formatFileSize, isImageMime } from '@/lib/attachments';

describe('formatFileSize', () => {
    it('renders raw bytes under a kilobyte', () => {
        expect(formatFileSize(0)).toBe('0 B');
        expect(formatFileSize(512)).toBe('512 B');
    });

    it('renders whole kilobytes', () => {
        // 84 * 1024
        expect(formatFileSize(86016)).toBe('84 KB');
    });

    it('keeps one decimal for small magnitudes and drops it once past ten', () => {
        // 2.1 * 1024 * 1024
        expect(formatFileSize(2202010)).toBe('2.1 MB');
        // 212 * 1024 * 1024
        expect(formatFileSize(222298112)).toBe('212 MB');
    });

    it('promotes to gigabytes past a thousand megabytes', () => {
        // 2 * 1024^3
        expect(formatFileSize(2147483648)).toBe('2 GB');
    });
});

describe('isImageMime', () => {
    it('treats raster image types as inline-renderable', () => {
        expect(isImageMime('image/png')).toBe(true);
        expect(isImageMime('image/jpeg')).toBe(true);
        expect(isImageMime('IMAGE/PNG')).toBe(true);
    });

    it('excludes SVG (download-only) and every non-image type', () => {
        expect(isImageMime('image/svg+xml')).toBe(false);
        expect(isImageMime('application/pdf')).toBe(false);
        expect(isImageMime('text/plain')).toBe(false);
    });
});
