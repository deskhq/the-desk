<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\LogoutResponse;
use App\Http\Responses\RegisterResponse;
use App\Http\Responses\TwoFactorLoginResponse;
use App\Http\Responses\VerifyEmailResponse;
use App\Models\TeamInvitation;
use App\Services\Sso\LdapAuthenticator;
use Database\Seeders\DemoSeeder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
        $this->app->singleton(VerifyEmailResponseContract::class, VerifyEmailResponse::class);
        $this->app->singleton(TwoFactorLoginResponseContract::class, TwoFactorLoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->configureLdapAuthentication();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Route the login form through directory bind authentication when LDAP is
     * configured.
     *
     * The same login POST serves both directory and local-password users, so the
     * callback tries an LDAP bind first and only falls back to the local password
     * database when SSO is not enforced. Under enforcement (AUTH_SSO_ONLY with a
     * provider configured) the directory is the sole credential source, so a
     * failed bind is a failed login rather than a local-password fallback — this
     * is how enforcement blocks the local path while still allowing the bind that
     * shares the same route. When LDAP is not configured the callback is not
     * registered at all, leaving Fortify's default credential check untouched.
     */
    private function configureLdapAuthentication(): void
    {
        if (! config('sso.ldap.enabled')) {
            return;
        }

        Fortify::authenticateUsing(function (Request $request): ?Authenticatable {
            $user = app(LdapAuthenticator::class)->attempt(
                (string) $request->input(Fortify::username()),
                (string) $request->input('password'),
            );

            if ($user instanceof Authenticatable) {
                return $user;
            }

            if (config('sso.enforced')) {
                return null;
            }

            return $this->attemptLocalPassword($request);
        });
    }

    /**
     * Validate the request against the local password database.
     *
     * Mirrors Fortify's default credential check (the branch we replace by
     * registering an authenticateUsing callback) so a break-glass password
     * account keeps working alongside the directory when SSO is not enforced.
     */
    private function attemptLocalPassword(Request $request): ?Authenticatable
    {
        $provider = Auth::guard(config('fortify.guard'))->getProvider();

        $credentials = [
            Fortify::username() => $request->input(Fortify::username()),
            'password' => $request->input('password'),
        ];

        $user = $provider->retrieveByCredentials($credentials);

        if ($user && $provider->validateCredentials($user, $credentials)) {
            return $user;
        }

        return null;
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
            'teamInvitation' => $this->teamInvitation($request),
            // Whether to surface the passwordless passkey sign-in affordance:
            // the deploy-time toggle is on and SSO is not enforcing an external
            // identity provider (which would own authentication).
            'canLoginWithPasskey' => (bool) config('fortify.passkeys_enabled') && ! config('sso.enforced'),
            // On the public demo, everyone signs in as the same shared owner, so
            // the login page advertises the credentials outright. Null (and the
            // hint hidden) on a real deployment.
            'demoCredentials' => config('demo.mode') ? [
                'email' => DemoSeeder::DEMO_EMAIL,
                'password' => DemoSeeder::DEMO_PASSWORD,
            ] : null,
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn (Request $request) => Inertia::render('auth/Register', [
            'teamInvitation' => $this->teamInvitation($request),
        ]));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));

        Fortify::twoFactorChallengeView(fn (Request $request) => Inertia::render('auth/TwoFactorChallenge', [
            'status' => $request->session()->get('status'),
        ]));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        // The 2FA challenge runs on a session already past the password step, so
        // it is keyed by the pending login id — an attacker holding the password
        // gets five TOTP/recovery-code guesses a minute, not free rein over the
        // 6-digit space. Falls back to the IP when no login is pending.
        RateLimiter::for('two-factor', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('two-factor|'.($request->session()->get('login.id') ?? $request->ip())));

        // Passkey routes span the guest login ceremony (no identity yet — key by
        // IP) and the authenticated management endpoints (key by user id).
        RateLimiter::for('passkeys', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('passkeys|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

    }

    /**
     * Get the pending team invitation context for auth pages.
     *
     * @return array{code: string, teamName: string}|null
     */
    private function teamInvitation(Request $request): ?array
    {
        $invitationCode = $request->query('invitation');

        if (! is_string($invitationCode)) {
            return null;
        }

        $invitation = TeamInvitation::query()
            ->with('team')
            ->where('code', $invitationCode)
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->first();

        if (! $invitation) {
            return null;
        }

        return [
            'code' => $invitation->code,
            'teamName' => $invitation->team->name,
        ];
    }
}
