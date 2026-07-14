<?php

namespace App\Providers;

use App\Http\Controllers\Scim\ScimUserController;
use App\Http\Middleware\EnsureScimBearerToken;
use App\Scim\ScimConfig;
use App\Services\Sso\GenericOidcProvider;
use ArieTimmerman\Laravel\SCIMServer\Http\Controllers\ResourceController;
use ArieTimmerman\Laravel\SCIMServer\RouteProvider;
use ArieTimmerman\Laravel\SCIMServer\SCIMConfig as PackageScimConfig;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Facades\Socialite;

class SsoServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    #[\Override]
    public function register(): void
    {
        // Point the SCIM server at this app's resource map (Users only) and its
        // identity-aware controller, so the package's routes resolve to logic
        // that flows through the shared provisioning layer.
        $this->app->bind(PackageScimConfig::class, ScimConfig::class);
        $this->app->bind(ResourceController::class, ScimUserController::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->registerOidcDriver();
        $this->registerScimRoutes();
    }

    /**
     * Register the generic OpenID Connect Socialite driver.
     *
     * The driver's endpoints come from the issuer's discovery document, so a
     * single env configuration works against any conformant OIDC provider. The
     * discovery URL defaults to the standard well-known path off the issuer, and
     * the requested scopes can be overridden from config.
     */
    private function registerOidcDriver(): void
    {
        Socialite::extend('oidc', function (): GenericOidcProvider {
            /** @var array<string, mixed> $config */
            $config = config('services.oidc');

            /** @var GenericOidcProvider $provider */
            $provider = Socialite::buildProvider(GenericOidcProvider::class, $config);

            $discoveryUrl = filled($config['discovery_url'] ?? null)
                ? $config['discovery_url']
                : rtrim((string) ($config['issuer'] ?? ''), '/').'/.well-known/openid-configuration';

            $provider->setDiscoveryUrl($discoveryUrl);

            if (filled($config['scopes'] ?? null)) {
                $provider->setScopes(explode(' ', (string) $config['scopes']));
            }

            return $provider;
        });
    }

    /**
     * Mount the SCIM 2.0 routes when provisioning is configured.
     *
     * The routes are deliberately not auto-published by the package (see
     * config/scim.php): they exist only when a bearer token is set, and every
     * one — the /Users surface and the discovery endpoints — is wrapped in the
     * token guard, so an unauthenticated provisioning API is never exposed. The
     * base path is operator-configurable via SCIM_BASE_PATH.
     *
     * When routes are cached (`route:cache`, as production does) the cached table
     * already contains these routes, so re-registering here would duplicate them;
     * the guard skips that. Toggling SCIM then requires rebuilding the route cache,
     * like any route change.
     */
    private function registerScimRoutes(): void
    {
        if ($this->app->routesAreCached() || ! config('sso.scim.enabled')) {
            return;
        }

        RouteProvider::routes([
            'path' => config('sso.scim.path'),
            'middleware' => [EnsureScimBearerToken::class],
            'public_middleware' => [EnsureScimBearerToken::class],
        ]);
    }
}
