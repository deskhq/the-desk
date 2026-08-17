<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\User;
use Laravel\Fortify\Features;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class TwoFactorStateData extends Data
{
    /**
     * @param  array<int, string>  $recoveryCodes
     */
    public function __construct(
        public bool $pendingConfirmation,
        public ?string $qrSvg,
        public ?string $secretKey,
        public array $recoveryCodes,
    ) {}

    /**
     * Build the user's two-factor enrolment state for the settings UI, or `null`
     * before enrolment begins.
     *
     * The QR code and plaintext secret are surfaced only during the
     * pending-confirmation window — the `confirm` option is enabled and the user
     * has not yet confirmed — so an already-active factor never serialises its
     * seed on a settings load. Once confirmed (or when confirmation is disabled,
     * where a set secret is immediately active) only the owner's recovery codes
     * remain.
     */
    public static function fromUser(User $user): ?self
    {
        if ($user->two_factor_secret === null) {
            return null;
        }

        $pendingConfirmation = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm')
            && $user->two_factor_confirmed_at === null;

        return new self(
            pendingConfirmation: $pendingConfirmation,
            qrSvg: $pendingConfirmation ? $user->twoFactorQrCodeSvg() : null,
            secretKey: $pendingConfirmation ? decrypt($user->two_factor_secret) : null,
            recoveryCodes: $user->recoveryCodes(),
        );
    }
}
