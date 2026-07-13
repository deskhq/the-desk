import { formatNumber } from '@/lib/numbers';

const KILOBYTE = 1024;
const MEGABYTE = KILOBYTE * 1024;
const GIGABYTE = MEGABYTE * 1024;

/**
 * Round a unit quantity for display: one decimal below ten (so "2.1 MB" reads
 * precisely) and whole numbers above it (so "212 MB" isn't noisy), formatted in
 * the active locale.
 */
function formatQuantity(value: number): string {
    const rounded =
        value < 10 ? Math.round(value * 10) / 10 : Math.round(value);

    return formatNumber(rounded);
}

/**
 * Render a byte count as a human-readable size (e.g. "84 KB", "2.1 MB"), scaling
 * to the largest unit that keeps the number small and formatting the digits in
 * the active locale.
 */
export function formatFileSize(bytes: number): string {
    if (bytes < KILOBYTE) {
        return `${formatNumber(Math.round(bytes))} B`;
    }

    if (bytes < MEGABYTE) {
        return `${formatQuantity(bytes / KILOBYTE)} KB`;
    }

    if (bytes < GIGABYTE) {
        return `${formatQuantity(bytes / MEGABYTE)} MB`;
    }

    return `${formatQuantity(bytes / GIGABYTE)} GB`;
}

/**
 * Whether a MIME type should render inline as an image in the composer tray and
 * timeline. SVG is excluded — it is an XSS vector, so it is always a download —
 * as is every non-image type.
 */
export function isImageMime(mime: string): boolean {
    const normalized = mime.toLowerCase();

    return normalized.startsWith('image/') && normalized !== 'image/svg+xml';
}
