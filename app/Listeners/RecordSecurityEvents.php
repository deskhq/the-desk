<?php

namespace App\Listeners;

use App\Enums\SecurityEventType;
use App\Support\SecurityEventRecorder;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;

/**
 * Records framework and Fortify authentication events into the security
 * activity log. Each `handle*` method is wired to its event by Laravel's event
 * discovery. Runs synchronously (never queued) so the live request's IP and
 * User-Agent are available when the event is captured.
 */
class RecordSecurityEvents
{
    public function __construct(private readonly SecurityEventRecorder $recorder) {}

    /**
     * Handle a successful sign in.
     */
    public function handleLogin(Login $event): void
    {
        $this->recorder->record($event->user, SecurityEventType::LoggedIn);
    }

    /**
     * Handle a sign out, ignoring guests without an authenticated user.
     */
    public function handleLogout(Logout $event): void
    {
        if ($event->user === null) {
            return;
        }

        $this->recorder->record($event->user, SecurityEventType::LoggedOut);
    }

    /**
     * Handle a password reset completed via the forgot-password flow.
     */
    public function handlePasswordReset(PasswordReset $event): void
    {
        $this->recorder->record($event->user, SecurityEventType::PasswordReset);
    }

    /**
     * Handle two-factor authentication being enabled.
     */
    public function handleTwoFactorEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        $this->recorder->record($event->user, SecurityEventType::TwoFactorEnabled);
    }

    /**
     * Handle two-factor authentication being disabled.
     */
    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->recorder->record($event->user, SecurityEventType::TwoFactorDisabled);
    }

    /**
     * Handle two-factor authentication being confirmed.
     */
    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->recorder->record($event->user, SecurityEventType::TwoFactorConfirmed);
    }

    /**
     * Handle a fresh set of recovery codes being generated.
     */
    public function handleRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        $this->recorder->record($event->user, SecurityEventType::RecoveryCodesGenerated);
    }
}
