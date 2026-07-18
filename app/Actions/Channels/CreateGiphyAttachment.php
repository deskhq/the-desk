<?php

declare(strict_types=1);

namespace App\Actions\Channels;

use App\Data\GiphyGifData;
use App\Enums\AttachmentSource;
use App\Enums\AttachmentStatus;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\User;

class CreateGiphyAttachment
{
    /**
     * Register a re-resolved Giphy GIF as a pending remote attachment.
     *
     * The row carries no blob — the media is hotlinked from `remote_url` — so it
     * mirrors the upload path's ownership (uploader + channel, no message yet) but
     * skips storage entirely. It is claimed by the send that follows via the same
     * `attachment_ids[]` flow, and swept by the same pending-orphan GC if never
     * sent. The channel + team are loaded so the caller can build the DTO
     * (and the `url` accessor) N+1-free.
     */
    public function handle(Channel $channel, User $user, GiphyGifData $gif): Attachment
    {
        $attachment = Attachment::create([
            'user_id' => $user->id,
            'channel_id' => $channel->id,
            'source' => AttachmentSource::Giphy,
            'mime_type' => 'image/gif',
            // A remote attachment has no stored bytes; the column is non-null.
            'size_bytes' => 0,
            'width' => $gif->width,
            'height' => $gif->height,
            'remote_url' => $gif->url,
            'description' => $gif->description,
            'status' => AttachmentStatus::Pending,
        ]);

        $attachment->setRelation('channel', $channel->loadMissing('team'));

        return $attachment;
    }
}
