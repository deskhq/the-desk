<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Enums\AuditAction;
use App\Enums\WebhookSubscriptionStatus;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookSubscription;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Registers an outgoing-webhook subscription for a team and records it in the
 * audit log. A fresh signing secret is minted here; the caller surfaces its
 * plaintext to the integrator once (the model stores it encrypted).
 */
class CreateWebhookSubscription
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    /**
     * @param  list<string>  $events  The subscribed event values (see App\Enums\WebhookEvent).
     * @param  list<string>|null  $channelIds  Optional channel allow-list; null means all channels.
     */
    public function handle(Team $team, User $actor, string $name, string $url, array $events, ?array $channelIds = null): WebhookSubscription
    {
        return DB::transaction(function () use ($team, $actor, $name, $url, $events, $channelIds): WebhookSubscription {
            $subscription = $team->webhookSubscriptions()->create([
                'created_by' => $actor->id,
                'name' => $name,
                'url' => $url,
                'secret' => WebhookSubscription::generateSecret(),
                'events' => $events,
                'channel_ids' => $channelIds,
                'status' => WebhookSubscriptionStatus::Active,
            ]);

            $this->recorder->record($team, $actor, AuditAction::WebhookSubscriptionCreated, $subscription, [
                'subscription_name' => $name,
            ]);

            return $subscription;
        });
    }
}
