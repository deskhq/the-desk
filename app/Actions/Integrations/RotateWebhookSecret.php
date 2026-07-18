<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Enums\AuditAction;
use App\Models\User;
use App\Models\WebhookSubscription;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Rotates a webhook subscription's signing secret and records it in the audit
 * log. A fresh secret is minted and stored (encrypted); the plaintext is
 * returned once for the integrator to copy, exactly like the create flow. The
 * old secret stops verifying immediately, so deliveries in flight must re-sign.
 */
class RotateWebhookSecret
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    /**
     * @return string The new plaintext signing secret, shown to the caller once.
     */
    public function handle(User $actor, WebhookSubscription $subscription): string
    {
        $secret = WebhookSubscription::generateSecret();

        return DB::transaction(function () use ($actor, $subscription, $secret): string {
            $subscription->forceFill(['secret' => $secret])->save();

            $this->recorder->record($subscription->team, $actor, AuditAction::WebhookSubscriptionSecretRotated, $subscription, [
                'subscription_name' => $subscription->name,
            ]);

            return $secret;
        });
    }
}
