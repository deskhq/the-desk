<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class DemoServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the public-demo guard rails.
     *
     * Everything here is gated on `config('demo.mode')`, so a real deployment is
     * completely unaffected: the mail transport is left alone and the throttles
     * resolve to "no limit". The named limiters are always registered (the write
     * routes reference them unconditionally so the route table stays stable);
     * they only bite when the demo flag is on.
     */
    public function boot(): void
    {
        $this->suppressOutboundMail();
        $this->configureRateLimiting();
    }

    /**
     * Swallow every outbound email while the demo is on.
     *
     * The demo signs every visitor in as the same account, so leaving mail on
     * would let a visitor fire invites, password resets, or verification mail at
     * an arbitrary address. Forcing the `array` transport keeps all of it in
     * memory (discarded at the end of the request) — invites, resets,
     * verification, and notifications alike — and it can't be defeated by an
     * operator who leaves SMTP configured.
     */
    private function suppressOutboundMail(): void
    {
        if (config('demo.mode')) {
            config(['mail.default' => 'array']);
        }
    }

    /**
     * Throttle demo entry, message sends, and attachment uploads by IP while the
     * demo is on.
     *
     * Every visitor shares one account, so per-user throttling is useless — the
     * key has to be the IP. The caps are generous enough that honest exploring
     * never trips them; only a flood does, and the hourly reset mops up whatever
     * slips through. Off the demo every limiter resolves to `Limit::none()`, so
     * the middleware these routes carry is a no-op.
     */
    private function configureRateLimiting(): void
    {
        // The one-click entry point mints a session per hit, so it gets the same
        // IP-keyed treatment: generous enough that a visitor bouncing in and out
        // never notices, tight enough that a script can't farm sessions.
        RateLimiter::for('demo-login', fn (Request $request): Limit => config('demo.mode')
            ? Limit::perMinute(10)->by((string) $request->ip())
            : Limit::none());

        RateLimiter::for('demo-messages', fn (Request $request): Limit => config('demo.mode')
            ? Limit::perMinute(30)->by((string) $request->ip())
            : Limit::none());

        RateLimiter::for('demo-uploads', fn (Request $request): Limit => config('demo.mode')
            ? Limit::perMinute(10)->by((string) $request->ip())
            : Limit::none());
    }
}
