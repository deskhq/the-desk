<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\TeamInvitation;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Hold a registration to the address its invitation was sent to.
 *
 * The register form locks the email field when it arrives under an invitation,
 * but a locked input is only an affordance — this is the control. An unknown or
 * no-longer-pending code is ignored rather than rejected: the person can still
 * create an account, they simply do not join the team on the strength of it.
 */
final readonly class MatchesTeamInvitation implements ValidationRule
{
    public function __construct(private ?string $invitationCode) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->invitationCode === null || ! is_string($value)) {
            return;
        }

        $invitation = TeamInvitation::query()
            ->where('code', $this->invitationCode)
            ->first();

        if (! $invitation instanceof TeamInvitation || ! $invitation->isPending()) {
            return;
        }

        if (strtolower($invitation->email) !== strtolower($value)) {
            $fail(__('This invitation was sent to a different email address.'));
        }
    }
}
