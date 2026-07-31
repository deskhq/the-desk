<?php

declare(strict_types=1);

namespace App\Enums;

use App\Data\MessageCredentialData;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Which kind of credential produced a message, for the one provenance
 * affordance that names it.
 *
 * A message arrives on exactly one path, so it carries at most one credential —
 * but the two kinds live in different tables, are revoked from different
 * surfaces, and read differently to the person acting on them. This names which
 * one is in hand so the client can pick the right wording and the right link
 * without a second nullable field per kind.
 *
 * @see MessageCredentialData
 */
#[TypeScript]
enum MessageCredentialKind: string
{
    case IncomingWebhook = 'incoming_webhook';
    case ApiToken = 'api_token';
}
