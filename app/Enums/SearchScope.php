<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How wide a message search reaches. The value is the `?scope` query-string
 * token the workspace scope control sends.
 *
 * `Team` (the default) searches only the current team's channels the user
 * belongs to; `All` spans every team they are a member of. Either way the visible
 * channel-id set is the whole ACL, so widening the scope can never surface a
 * channel the user is not in.
 */
enum SearchScope: string
{
    case Team = 'team';
    case All = 'all';

    /**
     * The scope applied when none is requested.
     */
    public static function default(): self
    {
        return self::Team;
    }
}
