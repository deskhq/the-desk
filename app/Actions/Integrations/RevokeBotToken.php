<?php

namespace App\Actions\Integrations;

use App\Enums\AuditAction;
use App\Models\User;
use App\Support\AuditRecorder;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Revokes a bot's API token and records the revocation in the workspace audit
 * log. Deleting the row immediately invalidates the token for every in-flight
 * and future request.
 */
class RevokeBotToken
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function handle(User $actor, PersonalAccessToken $token): void
    {
        $bot = $token->tokenable;
        assert($bot instanceof User);

        $tokenName = $token->name;
        $token->delete();

        $this->recorder->record($bot->ownerTeam()->firstOrFail(), $actor, AuditAction::BotTokenRevoked, $bot, [
            'token_name' => $tokenName,
            'bot_name' => $bot->name,
        ]);
    }
}
