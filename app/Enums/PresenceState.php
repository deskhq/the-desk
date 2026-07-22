<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How reachable a user is while they are connected.
 *
 * This is deliberately not a third "offline" case: whether someone is connected
 * at all is answered by the Reverb presence roster, which is instant and needs
 * no persistence. This enum only refines a *connected* user, and is composed
 * with the roster on the client.
 */
#[TypeScript]
enum PresenceState: string
{
    case Active = 'active';
    case Away = 'away';
}
