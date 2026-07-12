<?php

namespace App\Http\Controllers\Settings;

use App\Data\SecurityEventData;
use App\Data\SessionData;
use App\Enums\SecurityEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Models\SecurityEvent;
use App\Support\SecurityEventRecorder;
use App\Support\SessionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    /**
     * The number of recent security events to surface on the settings page.
     */
    private const int RECENT_ACTIVITY_LIMIT = 20;

    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request, SessionRegistry $registry): Response
    {
        $props = [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'sessions' => $this->activeSessions($request, $registry),
            'securityEvents' => $this->recentSecurityEvents($request),
        ];

        return Inertia::render('settings/Security', $props);
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
    private function activeSessions(TwoFactorAuthenticationRequest $request, SessionRegistry $registry): array
    {
        $currentSessionId = $request->session()->getId();

        return collect($registry->all($request->user()->id))
            ->map(fn (array $session): SessionData => SessionData::fromRegistry($session, $currentSessionId))
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
