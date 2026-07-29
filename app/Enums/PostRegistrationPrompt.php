<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\PasskeyAvailability;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A one-time account-security prompt offered on a newly registered user's first
 * landing in the workspace.
 *
 * The audience is a session-scoped fact — "the account created in this session" —
 * so the queued prompt lives in the session under {@see self::SESSION_KEY} rather
 * than on the user. Persistent rather than flashed, so a refresh before acting
 * still shows it; it dies with the session, which is correct because a returning
 * user is no longer "just registered".
 *
 * Deliberately an enum with a single case: the plumbing is generic so a later
 * prompt (two-factor enrolment being the obvious candidate) slots in as another
 * case without reworking the session key, the shared prop, or the dismissal
 * endpoint.
 */
#[TypeScript]
enum PostRegistrationPrompt: string
{
    /** Offer passkey (WebAuthn) enrolment, run inline in the prompt. */
    case Passkey = 'passkey';

    /**
     * The session key holding the prompt queued at registration.
     *
     * Not `onboarding_completed_at`: replaying the first-run tour from the user
     * menu would resurrect the prompt.
     */
    public const string SESSION_KEY = 'post_registration_prompt';

    /**
     * Whether this prompt can still be offered on this instance.
     *
     * Re-checked on every request rather than at registration, so an operator who
     * switches the feature off between sign-up and the first landing withdraws
     * the prompt instead of showing something that would 404.
     */
    public function isAvailable(): bool
    {
        return match ($this) {
            self::Passkey => PasskeyAvailability::enabled(),
        };
    }
}
