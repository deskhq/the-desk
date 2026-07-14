<?php

namespace App\Providers;

use App\Services\Sso\GenericOidcProvider;
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
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->registerOidcDriver();
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
}
