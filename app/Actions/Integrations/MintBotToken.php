<?php

namespace App\Actions\Integrations;

use App\Enums\AuditAction;
use App\Enums\IntegrationScope;
use App\Models\User;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;

/**
 * Mints a hashed API token for a bot, scoped to a set of {@see IntegrationScope}
 * abilities, and records the mint in the workspace audit log. The plain-text
 * token is returned once (Sanctum stores only its hash) for the caller to hand
 * to the operator — its value is never logged.
 */
class MintBotToken
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    /**
     * @param  list<string>  $abilities  The granted scopes (least-privilege).
     */
    public function handle(User $bot, User $actor, string $name, array $abilities): NewAccessToken
    {
        return DB::transaction(function () use ($bot, $actor, $name, $abilities): NewAccessToken {
            $token = $bot->createToken($name, $abilities);

            $this->recorder->record($bot->ownerTeam()->firstOrFail(), $actor, AuditAction::BotTokenCreated, $bot, [
                'token_name' => $name,
                'bot_name' => $bot->name,
            ]);

            return $token;
        });
    }
}
