<?php

namespace App\Http\Resources\Api\V1;

use App\Models\WebhookDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The stable public-API shape of a single delivery attempt, shown in a
 * subscription's recent-attempts log.
 *
 * @mixin WebhookDelivery
 */
class WebhookDeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->event_type,
            'event_id' => $this->event_id,
            'succeeded' => $this->succeeded,
            'response_status' => $this->response_status,
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
