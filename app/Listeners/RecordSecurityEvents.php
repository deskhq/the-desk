<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\SecurityEventType;
use App\Events\SecurityEventOccurred;
use App\Support\SecurityEventRecorder;
use App\Support\SessionRegistry;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

/**
 * Records framework, Fortify and application security events into the security
 * activity log. Each `handle*` method is wired to its event by Laravel's event
 * discovery. Runs synchronously (never queued) so the live request's IP and
 * User-Agent are available when the event is captured.
 *
 * This listener is the one place that reaches for the request: it holds the
 * HTTP coupling so {@see SecurityEventRecorder} does not, which is what lets a
 * queued directory sync record the same facts (ADR-0005). Off a request — a
 * job, a console command — there is simply no device context to stamp.
 */
final readonly class RecordSecurityEvents
{
    public function __construct(
        private SecurityEventRecorder $recorder,
        private SessionRegistry $registry,
        private Request $request,
    ) {}

    /**
     * Handle an application security event raised outside the framework's own.
     */
    public function handleSecurityEvent(SecurityEventOccurred $event): void
    {
        $this->record($event->user, $event->type);
    }

    /**
     * Handle a successful sign in.
     */
    public function handleLogin(Login $event): void
    {
        $this->record($event->user, SecurityEventType::LoggedIn);
    }

    /**
     * Handle a sign out, ignoring guests without an authenticated user.
     */
    public function handleLogout(Logout $event): void
    {
        if ($event->user === null) {
            return;
        }

        $this->registry->forget((string) $event->user->getAuthIdentifier(), session()->getId());

        $this->record($event->user, SecurityEventType::LoggedOut);
    }

    /**
     * Handle a password reset completed via the forgot-password flow.
     *
     * A reset is how someone recovers an account they may have lost control of,
     * so every session is revoked. Unlike the in-app change there is none to
     * spare: the flow is unauthenticated and lands on the login screen.
     */
    public function handlePasswordReset(PasswordReset $event): void
    {
        $this->registry->flush((string) $event->user->getAuthIdentifier());

        $this->record($event->user, SecurityEventType::PasswordReset);
    }

    /**
     * Handle two-factor authentication being enabled.
     */
    public function handleTwoFactorEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        $this->record($event->user, SecurityEventType::TwoFactorEnabled);
    }

    /**
     * Handle two-factor authentication being disabled.
     */
    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->record($event->user, SecurityEventType::TwoFactorDisabled);
    }

    /**
     * Handle two-factor authentication being confirmed.
     */
    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->record($event->user, SecurityEventType::TwoFactorConfirmed);
    }

    /**
     * Handle a fresh set of recovery codes being generated.
     */
    public function handleRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        $this->record($event->user, SecurityEventType::RecoveryCodesGenerated);
    }

    /**
     * Handle a passkey being registered.
     */
    public function handlePasskeyRegistered(PasskeyRegistered $event): void
    {
        $this->record($event->user, SecurityEventType::PasskeyRegistered);
    }

    /**
     * Handle a passkey being removed.
     */
    public function handlePasskeyDeleted(PasskeyDeleted $event): void
    {
        $this->record($event->user, SecurityEventType::PasskeyRemoved);
    }

    /**
     * Record the event, stamping the device context of the request behind it.
     */
    private function record(Authenticatable $user, SecurityEventType $type): void
    {
        $this->recorder->record($user, $type, $this->request->ip(), $this->request->userAgent());
    }
}
