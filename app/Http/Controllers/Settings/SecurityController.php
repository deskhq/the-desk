<?php

namespace App\Http\Controllers\Settings;

use App\Data\PasskeyData;
use App\Data\SecurityEventData;
use App\Data\SessionData;
use App\Data\TwoFactorStateData;
use App\Enums\SecurityEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Models\SecurityEvent;
use App\Support\IpGeolocator;
use App\Support\SecurityEventRecorder;
use App\Support\SessionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Passkey;

class SecurityController extends Controller
{
    /**
     * The number of recent security events to surface on the settings page.
     */
    private const int RECENT_ACTIVITY_LIMIT = 20;

    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request, SessionRegistry $registry, IpGeolocator $geolocator): Response
    {
        $props = [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'sessions' => $this->activeSessions($request, $registry, $geolocator),
            'securityEvents' => $this->recentSecurityEvents($request),
            'canManageTwoFactor' => $this->canManageTwoFactor(),
            'canManagePasskeys' => $this->canManagePasskeys(),
        ];

        if ($this->canManageTwoFactor()) {
            $props['twoFactorEnabled'] = $request->user()->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(
                Features::twoFactorAuthentication(),
                'confirm',
            );
            $props['twoFactor'] = TwoFactorStateData::fromUser($request->user());
        }

        if ($this->canManagePasskeys()) {
            $props['passkeys'] = $this->registeredPasskeys($request);
        }

        return Inertia::render('settings/Security', $props);
    }

    /**
     * Whether the app-native two-factor settings should be offered.
     *
     * Gated by the deploy-time toggle, and suppressed under SSO enforcement where
     * the identity provider owns MFA and local passwords are unusable.
     */
    private function canManageTwoFactor(): bool
    {
        return (bool) config('fortify.two_factor_enabled') && ! config('sso.enforced');
    }

    /**
     * Whether app-native passkey management should be offered.
     *
     * Gated by the deploy-time toggle, and suppressed under SSO enforcement where
     * the identity provider owns authentication.
     */
    private function canManagePasskeys(): bool
    {
        return (bool) config('fortify.passkeys_enabled') && ! config('sso.enforced');
    }

    /**
     * List the user's registered passkeys, most recently added first.
     *
     * @return array<int, PasskeyData>
     */
    private function registeredPasskeys(TwoFactorAuthenticationRequest $request): array
    {
        return $request->user()
            ->passkeys()
            ->latest()
            ->get()
            ->map(fn (Passkey $passkey): PasskeyData => PasskeyData::fromModel($passkey))
            ->all();
    }

    /**
     * List the user's most recent security activity, newest first.
     *
     * @return array<int, SecurityEventData>
     */
    private function recentSecurityEvents(TwoFactorAuthenticationRequest $request): array
    {
        return $request->user()
            ->securityEvents()
            ->limit(self::RECENT_ACTIVITY_LIMIT)
            ->get()
            ->map(fn (SecurityEvent $event): SecurityEventData => SecurityEventData::fromEvent($event))
            ->all();
    }

    /**
     * List the user's active sessions, current session first, then most recent.
     *
     * @return array<int, SessionData>
     */
    private function activeSessions(TwoFactorAuthenticationRequest $request, SessionRegistry $registry, IpGeolocator $geolocator): array
    {
        $currentSessionId = $request->session()->getId();

        return collect($registry->all($request->user()->id))
            ->map(fn (array $session): SessionData => SessionData::fromRegistry(
                $session,
                $currentSessionId,
                $geolocator->locate($session['ip_address']),
            ))
            ->sortByDesc('isCurrentDevice')
            ->values()
            ->all();
    }

    /**
     * Update the user's password.
     *
     * The in-app password change fires no framework event, so the security
     * activity is recorded explicitly here.
     */
    public function update(PasswordUpdateRequest $request, SecurityEventRecorder $recorder): RedirectResponse
    {
        $user = $request->user();

        $user->update([
            'password' => $request->password,
        ]);

        $recorder->record($user, SecurityEventType::PasswordChanged);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return back();
    }
}
