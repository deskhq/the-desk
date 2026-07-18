<?php

namespace App\Http\Resources\Api\V1;

use App\Data\ChannelData;
use App\Models\Channel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The stable public-API shape of a channel. Decoupled from the viewer-relative
 * {@see ChannelData} DTO (which folds in per-user sidebar state); the
 * API contract exposes only the channel's own durable attributes.
 *
 * @mixin Channel
 */
class ChannelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'visibility' => $this->visibility->value,
            'topic' => $this->topic,
            'is_general' => $this->isGeneral(),
            'is_archived' => $this->isArchived(),
            'is_direct' => $this->isDirect(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
