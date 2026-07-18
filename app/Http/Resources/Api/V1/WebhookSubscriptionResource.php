<?php

namespace App\Http\Resources\Api\V1;

use App\Models\WebhookSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The stable public-API shape of a webhook subscription. The signing secret is
 * deliberately absent — it is returned exactly once, alongside the create
 * response, and never again.
 *
 * @mixin WebhookSubscription
 */
class WebhookSubscriptionResource extends JsonResource
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
            'url' => $this->url,
            'events' => $this->events,
            'channel_ids' => $this->channel_ids,
            'status' => $this->status->value,
            'consecutive_failures' => $this->consecutive_failures,
            'last_success_at' => $this->last_success_at?->toIso8601String(),
            'disabled_at' => $this->disabled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'deliveries' => WebhookDeliveryResource::collection($this->whenLoaded('deliveries')),
        ];
    }
}
