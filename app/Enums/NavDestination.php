<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The dock's pinned navigation destinations, as they appear in the `?nav=` query
 * param on a workspace route.
 *
 * The server-side mirror of the rail's and the tab bar's destination list: open
 * state itself lives in the client, but the URL is shared ground — it decides
 * which destination's props ride along with a shell route, and which one a legacy
 * full-page URL redirects onto.
 */
enum NavDestination: string
{
    case Channels = 'channels';
    case Threads = 'threads';
    case Reminders = 'reminders';
    case Search = 'search';
    case You = 'you';

    /** The query param carrying the open destination. */
    public const string QUERY_PARAM = 'nav';

    /**
     * The destination a request pins open, or null when it pins none.
     *
     * Read leniently, like every other facet a shared link can carry: an
     * unrecognised value names no destination rather than erroring, so a
     * hand-edited URL withholds a panel's props instead of rejecting the shell.
     */
    public static function fromQuery(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }
}
