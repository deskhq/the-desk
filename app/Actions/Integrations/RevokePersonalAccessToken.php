<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Enums\AuditAction;
use App\Models\PersonalAccessToken;
use App\Models\Team;
use App\Models\User;
use App\Support\AuditRecorder;

/**
 * Revokes a human's own personal access token and records the revocation in the
 * bound team's audit log. Deleting the row immediately invalidates the token
 * for every in-flight and future request.
 */
class RevokePersonalAccessToken
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function handle(User $user, PersonalAccessToken $token): void
    {
        $team = $token->team;
        $tokenName = $token->name;

        $token->delete();

        if ($team instanceof Team) {
            $this->recorder->record($team, $user, AuditAction::PersonalAccessTokenRevoked, $user, [
                'token_name' => $tokenName,
            ]);
        }
    }
}
