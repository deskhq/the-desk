<?php

declare(strict_types=1);

namespace App\Support\Integrations;

use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\PersonalAccessToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * The single authorization primitive for the public API, aware of both subject
 * kinds a token can represent.
 *
 * A **bot** is team-scoped through its `owner_team_id` and may act only on a
 * channel it is a {@see ChannelMember} of — the human web policies
 * lean on a `team_members` pivot the bot lacks, so the bot path grounds access
 * on channel membership instead.
 *
 * A **human personal access token** is bound to one team and acts with the
 * person's real memberships and permissions: access is the same `view` policy
 * the web uses (a public channel in the team, or a private channel they belong
 * to), and every downstream action defers to the human policies. Either way a
 * channel outside the subject's team, or one it cannot see, is refused as if it
 * does not exist (404), never leaking its existence.
 */
class ApiChannelAccess
{
    /**
     * The team the subject is acting within: a bot's owner team, or the team a
     * human's personal access token is bound to. A human token with no bound
     * team (a revoked team nulled the FK) can act nowhere and is refused (403).
     */
    public static function team(User $subject): Team
    {
        if ($subject->isBot()) {
            return $subject->ownerTeam()->firstOrFail();
        }

        $token = $subject->currentAccessToken();

        abort_if(! $token instanceof PersonalAccessToken || $token->team_id === null, 403);

        return $token->team()->firstOrFail();
    }

    /**
     * Whether the subject may see and act within the channel at all.
     */
    public static function allows(User $subject, Channel $channel): bool
    {
        if ($channel->team_id !== self::team($subject)->id) {
            return false;
        }

        return $subject->isBot()
            ? $channel->channelMembers()->where('user_id', $subject->id)->exists()
            : Gate::forUser($subject)->allows('view', $channel);
    }

    /**
     * Abort with a 404 unless the subject may see and act within the channel.
     */
    public static function assert(User $subject, Channel $channel): void
    {
        abort_unless(self::allows($subject, $channel), 404);
    }
}
