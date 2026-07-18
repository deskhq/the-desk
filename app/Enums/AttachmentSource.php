<?php

declare(strict_types=1);

namespace App\Enums;

enum AttachmentSource: string
{
    /**
     * The attachment is an operator-hosted blob uploaded by a member. Its bytes
     * live on the configured disk (`disk`/`path`), are served through the
     * authorized download route, and are reclaimed on force-delete.
     */
    case Upload = 'upload';

    /**
     * The attachment is a remote GIF resolved from Giphy. It has no blob: `disk`,
     * `path`, and `original_filename` are null, and its media is hotlinked from
     * the Giphy CDN via `remote_url` (bypassing the blob-only serve route).
     */
    case Giphy = 'giphy';
}
