<?php

namespace App\Data;

use App\Models\WebhookDelivery;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One recorded delivery attempt as shown in a subscription's delivery log — the
 * event, the endpoint's answer (status + latency), which retry it was, and any
 * error summary.
 */
#[TypeScript]
class WebhookDeliveryData extends Data
{
    public function __construct(
        public string $id,
        public string $eventType,
        public string $eventId,
        public bool $succeeded,
        public ?int $responseStatus,
        public ?int $durationMs,
        public int $attempt,
        public ?string $error,
        public string $createdAt,
    ) {}

    /**
     * Build the DTO from a delivery model.
     */
    public static function fromModel(WebhookDelivery $delivery): self
    {
        return new self(
            id: $delivery->id,
            eventType: $delivery->event_type,
            eventId: $delivery->event_id,
            succeeded: $delivery->succeeded,
            responseStatus: $delivery->response_status,
            durationMs: $delivery->duration_ms,
            attempt: $delivery->attempt,
            error: $delivery->error,
            createdAt: $delivery->created_at?->toISOString() ?? '',
        );
    }
}
