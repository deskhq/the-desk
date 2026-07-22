<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Support\IpGeolocator;
use App\Support\PresenceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;
use Meilisearch\Client;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        $this->app->singleton(Client::class, fn (): Client => new Client(
            config('scout.meilisearch.host'),
            config('scout.meilisearch.key'),
        ));

        $this->app->bind(IpGeolocator::class, fn (): IpGeolocator => new IpGeolocator(
            (string) config('geolocation.database_path'),
        ));

        // One instance per request, so the presence aggregate a page needs for
        // dozens of rendered users costs one cache read per distinct user.
        $this->app->singleton(PresenceRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /**
     * Configure the public API's per-token rate limit at
     * `INTEGRATIONS_API_RATE_LIMIT` requests/minute.
     */
    protected function configureRateLimiting(): void
    {
        // Keyed on the presented bearer token (hashed, never stored raw) so each
        // bot integration is throttled independently at the operator-configured
        // rate; a hit yields a 429 with a Retry-After header.
        RateLimiter::for('integrations', fn (Request $request): Limit => Limit::perMinute(
            (int) config('integrations.api_rate_limit'),
        )->by(sha1((string) $request->bearerToken())));

        // Incoming webhooks authenticate by the opaque token in their URL, not a
        // bearer token, so they are throttled per URL token — two webhooks posting
        // from the same egress IP never share a quota. Same operator-configured
        // rate as the rest of the platform.
        RateLimiter::for('incoming-webhook', fn (Request $request): Limit => Limit::perMinute(
            (int) config('integrations.api_rate_limit'),
        )->by(sha1((string) $request->route('token'))));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
