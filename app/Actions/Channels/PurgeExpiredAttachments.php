<?php

declare(strict_types=1);

namespace App\Actions\Channels;

use App\Enums\AttachmentStatus;
use App\Models\Attachment;
use Illuminate\Support\Facades\DB;

class PurgeExpiredAttachments
{
    /**
     * Reclaim pending uploads that were never claimed by a message.
     *
     * A two-phase upload leaves a `pending` row the moment a file is dropped;
     * if the draft is abandoned or the send never arrives, that row (and its
     * blob) would linger forever. This sweep force-deletes every pending
     * attachment older than the configured TTL — force, not soft, so the
     * `forceDeleted` model event removes the blob too. Only pending rows are
     * touched, so a claimed attachment is never at risk however old it is.
     *
     * The cursor can hand back a row that a send has since claimed, so each
     * candidate is re-read under a row lock and only deleted while it is still
     * pending — a file claimed in that window is left for its message.
     *
     * @return int the number of attachments purged
     */
    public function handle(): int
    {
        $cutoff = now()->subHours((int) config('attachments.pending_ttl_hours'));

        $purged = 0;

        Attachment::query()
            ->where('status', AttachmentStatus::Pending)
            ->where('created_at', '<', $cutoff)
            ->cursor()
            ->each(function (Attachment $attachment) use (&$purged, $cutoff): void {
                $deleted = DB::transaction(function () use ($attachment, $cutoff): bool {
                    $fresh = Attachment::query()
                        ->whereKey($attachment->getKey())
                        ->where('status', AttachmentStatus::Pending)
                        ->where('created_at', '<', $cutoff)
                        ->lockForUpdate()
                        ->first();

                    if ($fresh === null) {
                        return false;
                    }

                    $fresh->forceDelete();

                    return true;
                });

                if ($deleted) {
                    $purged++;
                }
            });

        return $purged;
    }
}
