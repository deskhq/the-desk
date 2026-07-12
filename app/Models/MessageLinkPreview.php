<?php

namespace App\Models;

use App\Enums\LinkPreviewStatus;
use Database\Factories\MessageLinkPreviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $message_id
 * @property string $url
 * @property LinkPreviewStatus $status
 * @property string|null $title
 * @property string|null $description
 * @property string|null $image_url
 * @property string|null $site_name
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Message $message
 */
#[Fillable(['message_id', 'url', 'status', 'title', 'description', 'image_url', 'site_name', 'position'])]
class MessageLinkPreview extends Model
{
    /** @use HasFactory<MessageLinkPreviewFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the message this preview was extracted from.
     *
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
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
            'status' => LinkPreviewStatus::class,
            'position' => 'int',
        ];
    }
}
