<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Support\IpGeolocator;
use App\Support\PresenceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
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
        $this->configureQueueRouting();
        $this->configureQueueFailureLogging();
    }

    /**
     * Write every job that dies on a worker to the log.
     *
     * Without this, a failed job is invisible: it is consumed before it throws,
     * so the queue drains, the worker stays up, and the only record is a row in
     * `failed_jobs` that nothing reads (issue #866 — web push spent a whole
     * staging cycle dead this way). One line in the log puts it in front of
     * whatever an operator already watches, on the first failure rather than
     * whenever someone thinks to look.
     *
     * Registered for every queued job, not just the push path: any job that can
     * fail silently has the same problem, and there is no reason to learn this
     * lesson once per subsystem.
     */
    protected function configureQueueFailureLogging(): void
    {
        Queue::failing(function (JobFailed $event): void {
            Log::error('Queued job failed: '.$event->job->resolveName(), [
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'exception' => $event->exception,
            ]);
        });
    }

    /**
     * Keep every broadcast off the shared `default` queue.
     *
     * Broadcasts are latency-critical and tiny; the jobs they would otherwise
     * queue behind are neither — a link unfurl spends up to five seconds on
     * outbound HTTP, and a webhook delivery or an export longer still. One
     * registration covers every event, present and future, because queue routes
     * match a queueable's interfaces as well as its class.
     */
    protected function configureQueueRouting(): void
    {
        Queue::route(ShouldBroadcast::class, queue: 'broadcasts');
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

        // Replaying a delivery POSTs to a third-party endpoint on demand, so it
        // is throttled per acting admin — generous enough to walk a delivery log
        // after fixing an endpoint, tight enough not to become a traffic source.
        RateLimiter::for('webhook-replay', fn (Request $request): Limit => Limit::perMinute(30)
            ->by((string) $request->user()?->getAuthIdentifier()));
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
