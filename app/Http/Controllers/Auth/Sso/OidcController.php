<?php

namespace App\Http\Controllers\Auth\Sso;

use App\Actions\Sso\ProvisionSsoUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Contracts\User as OidcUser;
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

            $user = $provisionSsoUser->handle($this->providerKey(), (string) $subject, $email, $oidcUser->getName(), emailVerified: $this->emailVerified($oidcUser));
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
     * The provider key an OIDC identity is stored under, namespaced by issuer.
     *
     * An OIDC `sub` is only unique *within* an issuer, so folding the configured
     * issuer into the key (`oidc:{issuer}`) keeps two issuers that mint the same
     * `sub` from ever resolving to the same account. The trailing slash is
     * normalised away so `https://idp` and `https://idp/` are one issuer, not two.
     *
     * The issuer is always present here: this action 404s unless
     * `config('sso.oidc.enabled')`, which config/sso.php only sets true when
     * `SSO_OIDC_ISSUER` is filled — so the key can never collapse to a bare
     * `oidc:` that would reintroduce the cross-issuer collision.
     */
    private function providerKey(): string
    {
        return 'oidc:'.rtrim((string) config('services.oidc.issuer'), '/');
    }

    /**
     * Whether the IdP asserts this profile's email address as verified.
     *
     * Read from the raw UserInfo `email_verified` claim, which arrives as a
     * real boolean or as the string "true"/"false" depending on the IdP — both
     * forms are coerced. An absent claim is treated as verified by default
     * (many conformant IdPs simply omit it); SSO_OIDC_REQUIRE_VERIFIED_EMAIL
     * flips that to fail-closed for operators who want an explicit assertion
     * on every login. An unparseable claim value is treated the same as an
     * absent one rather than guessing a meaning for it.
     */
    private function emailVerified(OidcUser $oidcUser): bool
    {
        $claims = $oidcUser instanceof AbstractUser ? (array) $oidcUser->getRaw() : [];

        $claim = $claims['email_verified'] ?? null;

        if ($claim === null) {
            return ! config('sso.oidc.require_verified_email');
        }

        return filter_var($claim, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
            ?? ! config('sso.oidc.require_verified_email');
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
