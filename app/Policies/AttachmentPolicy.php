<?php

namespace App\Policies;

use App\Enums\AttachmentStatus;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class AttachmentPolicy
{
    /**
     * Determine whether the user can download (view) the attachment.
     *
     * Access mirrors reading the message the file belongs to: the user must be
     * able to view the channel, and a claimed file is only reachable while its
     * message is live — a soft-deleted message hides its attachments just as it
     * hides its body. A still-pending upload has no message yet, so only its
     * uploader may preview it (the composer's own pre-send preview).
     *
     * The controller turns a false result into a 404 rather than a 403 so the
     * endpoint never discloses that a file it won't serve exists.
     */
    public function view(User $user, Attachment $attachment): bool
    {
        if (! Gate::forUser($user)->allows('view', $attachment->channel)) {
            return false;
        }

        if ($attachment->status === AttachmentStatus::Pending) {
            return $attachment->user_id === $user->id;
        }

        return $attachment->message !== null && ! $attachment->message->trashed();
    }
}
