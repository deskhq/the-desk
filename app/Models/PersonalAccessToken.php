<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * The application's Sanctum token model, extended with a `team_id` so a human
 * personal access token can be bound to a single team.
 *
 * A bot token leaves `team_id` null (a bot is team-scoped through its
 * `owner_team_id`); a human PAT sets it at mint time so the token acts with the
 * person's memberships and permissions only within that one team. The public
 * API reads {@see self::team()} to scope every request.
 *
 * @property string|null $team_id
 * @property-read Team|null $team
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * The team a human PAT is confined to, or null for a bot token.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
