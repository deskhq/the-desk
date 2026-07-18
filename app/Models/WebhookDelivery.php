<?php

namespace App\Models;

use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recorded attempt to deliver a webhook event to a subscription's endpoint.
 * The log is append-only and shown (recent-first) on the subscription's show
 * response, so an integrator can see which events reached them and how the
 * endpoint answered.
 *
 * @property string $id
 * @property string $webhook_subscription_id
 * @property string $event_type
 * @property string $event_id
 * @property bool $succeeded
 * @property int|null $response_status
 * @property int|null $duration_ms
 * @property int $attempt
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WebhookSubscription $subscription
 */
#[Fillable(['webhook_subscription_id', 'event_type', 'event_id', 'succeeded', 'response_status', 'duration_ms', 'attempt', 'error'])]
class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the subscription this delivery attempt belongs to.
     *
     * @return BelongsTo<WebhookSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'webhook_subscription_id');
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
            'succeeded' => 'boolean',
            'response_status' => 'integer',
            'duration_ms' => 'integer',
            'attempt' => 'integer',
        ];
    }
}
