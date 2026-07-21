import { isAudioMime } from '@/lib/audio';
import type { AttachmentData } from '@/types/attachments';

/** The single-image preview box, matching design panel 1c (≤ 380 × 240). */
const SINGLE_MAX_WIDTH = 380;
const SINGLE_MAX_HEIGHT = 240;

/** At most four image tiles render in a grid; the rest collapse into a "+N" tile. */
const MAX_GRID_TILES = 4;

/**
 * Split a message's attachments into the inline images, the inline audio
 * players, and the download-card files, preserving order. The `isImage` flag is
 * computed server-side (false for SVG and every non-image type), so the client
 * never re-derives it; audio is recognised from the MIME type alone, since a
 * voice message is an ordinary audio attachment with no distinguishing data.
 */
export function partitionAttachments(attachments: AttachmentData[]): {
    images: AttachmentData[];
    audios: AttachmentData[];
    files: AttachmentData[];
} {
    const images: AttachmentData[] = [];
    const audios: AttachmentData[] = [];
    const files: AttachmentData[] = [];

    for (const attachment of attachments) {
        if (attachment.isImage) {
            images.push(attachment);
        } else if (isAudioMime(attachment.mimeType)) {
            audios.push(attachment);
        } else {
            files.push(attachment);
        }
    }

    return { images, audios, files };
}

/**
 * The reserved display box for a single image, scaled from its stored pixel
 * dimensions to fit within 380 × 240 without ever enlarging it. Sizing from the
 * stored dimensions means the tile holds its space before the image loads, so
 * the timeline never shifts. Falls back to the full box when dimensions are
 * unknown (a rare processing gap).
 */
export function singleImageSize(
    width: number | null,
    height: number | null,
): { width: number; height: number } {
    if (!width || !height) {
        return { width: SINGLE_MAX_WIDTH, height: SINGLE_MAX_HEIGHT };
    }

    const scale = Math.min(
        SINGLE_MAX_WIDTH / width,
        SINGLE_MAX_HEIGHT / height,
        1,
    );

    return {
        width: Math.round(width * scale),
        height: Math.round(height * scale),
    };
}

/**
 * The number of grid columns for a multi-image message: two share a row, three
 * tile in a row, and four-or-more fall into a 2×2 block (the fifth image onward
 * hides behind the "+N" tile).
 */
export function imageGridColumns(count: number): number {
    if (count === 3) {
        return 3;
    }

    return 2;
}

export type ImageGridTile = {
    attachment: AttachmentData;
    /** Index within the message's image list, so the lightbox opens at this tile. */
    index: number;
    /** The count of images hidden behind this tile ("+N"), or 0 when none. */
    overflow: number;
};

/**
 * The tiles to render for a 2+ image grid: up to four, with the count of any
 * further images folded into the last tile as its "+N" overflow badge.
 */
export function imageGridTiles(images: AttachmentData[]): ImageGridTile[] {
    const visible = images.slice(0, MAX_GRID_TILES);
    const hidden = images.length - visible.length;

    return visible.map((attachment, index) => ({
        attachment,
        index,
        overflow: hidden > 0 && index === visible.length - 1 ? hidden : 0,
    }));
}

/**
 * A short, upper-cased type label for a file card ("PDF", "ZIP"), taken from the
 * filename's extension and falling back to the MIME subtype when it has none.
 */
export function fileTypeLabel(filename: string, mime: string): string {
    const extension = filename.split('.').pop();

    if (extension && extension !== filename && extension.length <= 5) {
        return extension.toUpperCase();
    }

    const slash = mime.indexOf('/');
    const subtype = slash === -1 ? '' : mime.slice(slash + 1);

    return subtype === '' ? 'FILE' : subtype.toUpperCase();
}
