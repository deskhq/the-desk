<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Enums\AuditAction;
use App\Events\AuditableActionOccurred;
use App\Models\IncomingWebhook;
use App\Models\User;

/**
 * Revokes an incoming webhook and records the revocation in the workspace audit
 * log. Stamping `revoked_at` immediately stops the webhook resolving for every
 * in-flight and future post while keeping the row for the audit trail.
 */
final class RevokeIncomingWebhook
{
    public function handle(User $actor, IncomingWebhook $webhook): void
    {
        if ($webhook->revoked_at !== null) {
            return;
        }

        $webhook->forceFill(['revoked_at' => now()])->save();

        event(new AuditableActionOccurred($webhook->team, $actor, AuditAction::IncomingWebhookRevoked, $webhook, [
            'webhook_name' => $webhook->name,
            'bot_name' => $webhook->bot->name,
            'channel_name' => $webhook->channel?->name,
        ]));
    }
}
