<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\UserType;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A channel-member candidate is either a human on the team's `team_members`
 * roster or one of the team's bots.
 *
 * Bots are deliberately not `team_members` (they stay out of seat counts and
 * rosters), so a plain `exists('team_members', …)` rule would reject them even
 * though a bot must become a `ChannelMember` to post. This rule accepts both
 * kinds while still refusing anyone outside the team.
 */
class AddableChannelMember implements ValidationRule
{
    public function __construct(private readonly string $teamId) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $isTeamMember = DB::table('team_members')
            ->where('user_id', $value)
            ->where('team_id', $this->teamId)
            ->exists();

        if ($isTeamMember) {
            return;
        }

        $isTeamBot = User::query()
            ->whereKey($value)
            ->where('type', UserType::Bot->value)
            ->where('owner_team_id', $this->teamId)
            ->exists();

        if (! $isTeamBot) {
            $fail(__('The selected user is not part of this team.'));
        }
    }
}
