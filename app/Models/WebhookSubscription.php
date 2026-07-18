<?php

namespace App\Models;

use App\Enums\WebhookEvent;
use App\Enums\WebhookSubscriptionStatus;
use Database\Factories\WebhookSubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An outgoing-webhook subscription: a team-scoped registration that POSTs a
 * signed payload to an external {@see self::$url} whenever one of its subscribed
 * {@see WebhookEvent}s occurs in a channel it listens to. Deliveries retry with
 * backoff and the subscription auto-disables after too many consecutive
 * failures, so a dead endpoint stops costing the queue.
 *
 * @property string $id
 * @property string $team_id
 * @property string|null $created_by
 * @property string $name
 * @property string $url
 * @property string $secret
 * @property list<string> $events
 * @property list<string>|null $channel_ids
 * @property WebhookSubscriptionStatus $status
 * @property int $consecutive_failures
 * @property Carbon|null $last_success_at
 * @property Carbon|null $disabled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read User|null $creator
 * @property-read Collection<int, WebhookDelivery> $deliveries
 */
#[Fillable(['team_id', 'created_by', 'name', 'url', 'secret', 'events', 'channel_ids', 'status', 'consecutive_failures', 'last_success_at', 'disabled_at'])]
class WebhookSubscription extends Model
{
    /** @use HasFactory<WebhookSubscriptionFactory> */
    use HasFactory, HasUuids;

    /**
     * Generate a fresh signing secret to hand to a new subscription.
     *
     * The `whsec_` prefix mirrors the convention integrators recognise from
     * other webhook platforms, making an accidentally-leaked secret easy to spot.
     */
    public static function generateSecret(): string
    {
        return 'whsec_'.Str::random(48);
    }

    /**
     * Get the workspace the subscription belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the user (bot or admin) that registered the subscription.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the subscription's delivery-attempt log.
     *
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /**
     * Determine whether the subscription is currently delivering.
     */
    public function isActive(): bool
    {
        return $this->status === WebhookSubscriptionStatus::Active;
    }

    /**
     * Determine whether the subscription wants an event that happened in a given
     * channel: the event must be in its subscribed set and, when it carries a
     * channel allow-list, the channel must be on it (an unset/empty list means
     * every channel).
     */
    public function listensFor(WebhookEvent $event, ?string $channelId): bool
    {
        if (! in_array($event->value, $this->events, true)) {
            return false;
        }

        if ($this->channel_ids === null || $this->channel_ids === []) {
            return true;
        }

        return $channelId !== null && in_array($channelId, $this->channel_ids, true);
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
            'secret' => 'encrypted',
            'events' => 'array',
            'channel_ids' => 'array',
            'status' => WebhookSubscriptionStatus::class,
            'consecutive_failures' => 'integer',
            'last_success_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }
}
