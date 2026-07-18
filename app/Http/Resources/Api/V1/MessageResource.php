<?php

namespace App\Http\Resources\Api\V1;

use App\Data\MessageData;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The stable public-API shape of a message. Reactions are summarised as emoji
 * counts; the author is nested as a {@see UserResource}. Decoupled from the
 * frontend {@see MessageData} DTO so the v1 contract stays fixed.
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel_id' => $this->channel_id,
            'body' => $this->body,
            'type' => $this->type->value,
            'author' => new UserResource($this->whenLoaded('user')),
            'reply_to_id' => $this->reply_to_id,
            'thread_root_id' => $this->thread_root_id,
            'reactions' => $this->reactions
                ->groupBy('emoji')
                ->map(fn ($group, string $emoji): array => [
                    'emoji' => $emoji,
                    'count' => $group->count(),
                ])
                ->values()
                ->all(),
            'created_at' => $this->created_at?->toIso8601String(),
            'edited_at' => $this->edited_at?->toIso8601String(),
        ];
    }
}
