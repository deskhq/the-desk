<?php

namespace App\Actions\Integrations;

use App\Enums\AuditAction;
use App\Events\AuditableActionOccurred;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Revokes a bot's API token and records the revocation in the workspace audit
 * log. Deleting the row immediately invalidates the token for every in-flight
 * and future request.
 */
class RevokeBotToken
{
    public function handle(User $actor, PersonalAccessToken $token): void
    {
        $bot = $token->tokenable;
        assert($bot instanceof User);

        $tokenName = $token->name;
        $token->delete();

        event(new AuditableActionOccurred($bot->ownerTeam()->firstOrFail(), $actor, AuditAction::BotTokenRevoked, $bot, [
            'token_name' => $tokenName,
            'bot_name' => $bot->name,
        ]));
    }
}
