<?php

namespace App\Http\Controllers\Auth\Sso;

use App\Actions\Sso\ProvisionSsoUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

class OidcController extends Controller
{
    /**
     * Send the user to the configured OpenID Connect provider.
     */
    public function redirect(): SymfonyRedirectResponse
    {
        abort_unless(config('sso.oidc.enabled'), 404);

        return Socialite::driver('oidc')->redirect();
    }

    /**
     * Handle the provider callback: match or just-in-time provision the account,
     * then sign the user in.
     *
     * Any provider error (denied grant, invalid state, unreachable IdP), an
     * unusable profile (missing the email to match on or the stable subject to
     * key the identity), or a provisioning failure (e.g. a losing concurrent
     * first login hitting the unique constraint) fails gracefully back to the
     * login screen rather than surfacing an exception.
     */
    public function callback(Request $request, ProvisionSsoUser $provisionSsoUser): RedirectResponse
    {
        abort_unless(config('sso.oidc.enabled'), 404);

        try {
            $oidcUser = Socialite::driver('oidc')->user();

            $email = $oidcUser->getEmail();
            $subject = $oidcUser->getId();

            // Without a stable subject every malformed profile would collapse to
            // the same identity, so both it and the email are required.
            if (blank($email) || blank($subject)) {
                return $this->failed();
            }

            $user = $provisionSsoUser->handle('oidc', (string) $subject, $email, $oidcUser->getName());
        } catch (Throwable $e) {
            // Report before the friendly redirect so ops can tell a denied grant
            // from an IdP outage or a provisioning bug — otherwise every failure
            // mode collapses into the same silent bounce with no trail.
            report($e);

            return $this->failed();
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(config('fortify.home'));
    }

    /**
     * Bounce back to login with a friendly error after a failed sign-in.
     */
    private function failed(): RedirectResponse
    {
        return to_route('login')->with(
            'status',
            __('We could not sign you in through your identity provider. Please try again or use another sign-in method.'),
        );
    }
}
