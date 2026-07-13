<?php

namespace App\Models;

use App\Enums\AttachmentStatus;
use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $id
 * @property string|null $message_id
 * @property string $user_id
 * @property string $channel_id
 * @property string $disk
 * @property string $path
 * @property string $original_filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property string|null $thumb_path
 * @property AttachmentStatus $status
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $url
 * @property-read string|null $thumb_url
 * @property-read Message|null $message
 * @property-read User $user
 * @property-read Channel $channel
 */
#[Fillable(['message_id', 'user_id', 'channel_id', 'disk', 'path', 'original_filename', 'mime_type', 'size_bytes', 'width', 'height', 'thumb_path', 'status'])]
class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Mime types that are image-shaped but must never be rendered inline. SVG is
     * an XSS vector (it can carry script), so it is treated as a download even
     * though its mime starts with `image/`.
     *
     * @var list<string>
     */
    public const array NON_INLINE_IMAGE_MIMES = ['image/svg+xml'];

    /**
     * Delete the underlying blob(s) when the row is force-deleted. A soft delete
     * keeps the files (the serve policy already denies access to a soft-deleted
     * message), so only a force-delete — the pending-orphan GC, or a message
     * being permanently removed — reclaims storage. The generated thumbnail, when
     * present, is removed alongside the original.
     */
    #[\Override]
    protected static function booted(): void
    {
        static::forceDeleted(function (Attachment $attachment): void {
            $disk = Storage::disk($attachment->disk);
            $disk->delete($attachment->path);

            if ($attachment->thumb_path !== null) {
                $disk->delete($attachment->thumb_path);
            }
        });
    }

    /**
     * Get the message this attachment was claimed by, if any.
     *
     * A pending attachment has none. A claimed attachment resolves even when the
     * message is soft-deleted (withTrashed) so the serve policy can inspect the
     * message's channel and deleted state to authorize the download.
     *
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class)->withTrashed();
    }

    /**
     * Get the member who uploaded the attachment.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the channel the attachment was uploaded to. Captured at upload so both
     * the post-policy authorization and the serve authorization resolve before
     * any message claims the file.
     *
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * Whether the file should be rendered inline as an image. True for raster
     * image mimes; false for SVG (download-only, an XSS vector) and every other
     * type. The serve route reads this to choose inline vs attachment disposition.
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/')
            && ! in_array($this->mime_type, self::NON_INLINE_IMAGE_MIMES, true);
    }

    /**
     * The authorized download URL. Routes through the serve endpoint (never a
     * filesystem URL) so a private-channel file is only reachable by a member,
     * and pointing the disk at S3 later needs no change here. The channel and its
     * team are expected to be eager-loaded (via the message payload's relation
     * set) so building this per attachment stays N+1-free.
     *
     * @return Attribute<string, never>
     */
    protected function url(): Attribute
    {
        return Attribute::get(fn (): string => route('channels.attachments.download', [
            'team' => $this->channel->team->slug,
            'channel' => $this->channel->slug,
            'attachment' => $this->id,
        ]));
    }

    /**
     * The authorized thumbnail URL, or null when no thumbnail was generated (SVG
     * and every non-image type). Routes through the serve endpoint like {@see
     * url()} so the private-channel policy still gates it; the timeline grid reads
     * it and the lightbox falls back to the full-resolution {@see url()}.
     *
     * @return Attribute<covariant string|null, never>
     */
    protected function thumbUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->thumb_path === null ? null : route('channels.attachments.thumbnail', ['team' => $this->channel->team->slug, 'channel' => $this->channel->slug, 'attachment' => $this->id]));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'size_bytes' => 'int',
            'width' => 'int',
            'height' => 'int',
            'status' => AttachmentStatus::class,
        ];
    }
}
