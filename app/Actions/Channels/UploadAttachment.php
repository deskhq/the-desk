<?php

declare(strict_types=1);

namespace App\Actions\Channels;

use App\Enums\AttachmentStatus;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class UploadAttachment
{
    public function __construct(private readonly ProcessAttachmentImage $processImage) {}

    /**
     * Store an uploaded file and register it as a pending attachment.
     *
     * The blob lands on the configured (private) disk under a per-channel prefix.
     * Image dimensions are read straight from the upload with the built-in
     * getimagesize(), left null for non-images. Raster images are then processed
     * synchronously — EXIF stripped in place and a thumbnail generated — so no
     * un-stripped original is ever served and the timeline thumbnail is ready.
     * The row is owned by the uploader and channel only — it carries no message
     * until a send claims it — and returns with its channel + team loaded so the
     * caller can build the download URL N+1-free.
     */
    public function handle(Channel $channel, User $uploader, UploadedFile $file): Attachment
    {
        $disk = (string) config('attachments.disk');
        $path = $file->store("attachments/{$channel->id}", $disk);

        [$width, $height] = $this->dimensions($file);

        $attachment = Attachment::create([
            'user_id' => $uploader->id,
            'channel_id' => $channel->id,
            'disk' => $disk,
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'width' => $width,
            'height' => $height,
            'status' => AttachmentStatus::Pending,
        ]);

        $this->processImage->handle($attachment);

        $attachment->setRelation('channel', $channel->loadMissing('team'));

        return $attachment;
    }

    /**
     * Read an image upload's pixel dimensions, or [null, null] for a non-image
     * (getimagesize returns false, e.g. for a PDF or an SVG).
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function dimensions(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $size = $path === false ? false : @getimagesize($path);

        return $size === false ? [null, null] : [$size[0], $size[1]];
    }
}
