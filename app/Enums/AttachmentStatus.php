<?php

declare(strict_types=1);

namespace App\Enums;

enum AttachmentStatus: string
{
    /**
     * The file has been uploaded but not yet claimed by a message. Owned by the
     * uploader and channel only; reclaimed by the pending-orphan GC sweep if it
     * is never sent within the configured TTL.
     */
    case Pending = 'pending';

    /**
     * The file has been claimed by the message that sent it. Its `message_id` is
     * set and it is now served (and cleaned up) alongside that message.
     */
    case Attached = 'attached';
}
