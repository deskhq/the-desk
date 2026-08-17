<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Which followed threads the Threads panel lists.
 *
 * Server-side by design: the inbox is cursor-paginated, so filtering a loaded
 * page in the client would silently misreport what is unread.
 */
#[TypeScript]
enum ThreadInboxFilter: string
{
    case Unread = 'unread';
    case All = 'all';

    /**
     * Resolve the filter a request asks for, defaulting to the panel's own
     * default. Anything unrecognised falls back rather than erroring: the value
     * is a query param a shared link can carry, not user input worth a 422.
     */
    public static function fromQuery(mixed $value): self
    {
        return is_string($value) ? self::tryFrom($value) ?? self::default() : self::default();
    }

    /** The filter the panel opens on: what needs the viewer's attention. */
    public static function default(): self
    {
        return self::Unread;
    }
}
