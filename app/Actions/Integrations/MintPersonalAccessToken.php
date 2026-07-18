<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Enums\AuditAction;
use App\Enums\IntegrationScope;
use App\Models\Team;
use App\Models\User;
use App\Support\AuditRecorder;
use Laravel\Sanctum\NewAccessToken;

/**
 * Mints a hashed personal access token for a human, bound to a single team and
 * scoped to a set of {@see IntegrationScope} abilities, and records
 * the mint in that team's audit log. Unlike a bot token, the subject is a real
 * person, so the token acts with their memberships and permissions — but only
 * within the one team it is bound to. The plain-text token is returned once
 * (Sanctum stores only its hash); its value is never logged.
 */
class MintPersonalAccessToken
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    /**
     * @param  list<string>  $abilities  The granted scopes (least-privilege).
     */
    public function handle(User $user, Team $team, string $name, array $abilities): NewAccessToken
    {
        $token = $user->createToken($name, $abilities);

        $token->accessToken->forceFill(['team_id' => $team->id])->save();

        $this->recorder->record($team, $user, AuditAction::PersonalAccessTokenCreated, $user, [
            'token_name' => $name,
        ]);

        return $token;
    }
}
