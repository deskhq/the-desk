<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Integrations platform toggle
    |--------------------------------------------------------------------------
    |
    | Master switch for the integrations platform (bot users, the public REST
    | API, and webhooks). When disabled the /api/v1 surface 404s and the
    | management UI hides, so an operator who wants none of it can turn the whole
    | feature off. Defaults to on.
    |
    */

    'enabled' => (bool) env('INTEGRATIONS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Public API per-token rate limit
    |--------------------------------------------------------------------------
    |
    | The maximum number of requests a single bot token may make to /api/v1 per
    | minute. Exceeding it yields a 429 with a Retry-After header. Raise it for
    | busy integrations, or lower it to protect a small instance.
    |
    */

    'api_rate_limit' => (int) env('INTEGRATIONS_API_RATE_LIMIT', 60),

    /*
    |--------------------------------------------------------------------------
    | Outgoing webhook delivery
    |--------------------------------------------------------------------------
    |
    | Tuning for delivering subscribed domain events to external URLs. Each
    | delivery is signed (HMAC-SHA256) and retried with exponential backoff up
    | to `max_attempts` times; a request that outlives `timeout` seconds counts
    | as a failed attempt. A subscription whose deliveries fail `disable_after`
    | times in a row (with no success in between) is auto-disabled and stops
    | delivering until an integrator recreates it.
    |
    */

    'webhooks' => [
        'max_attempts' => (int) env('WEBHOOKS_MAX_ATTEMPTS', 5),
        'timeout' => (int) env('WEBHOOKS_TIMEOUT', 5),
        'disable_after' => (int) env('WEBHOOKS_DISABLE_AFTER', 5),

        /*
         * SSRF guard for outgoing deliveries. When on (default), webhook URLs
         * that aren't public http/https addresses — loopback, private,
         * link-local, and cloud-metadata targets — are rejected both at
         * registration and again before each delivery. Turn it off for a
         * locked-down instance that deliberately targets internal endpoints.
         */
        'block_private_urls' => (bool) env('WEBHOOKS_BLOCK_PRIVATE_URLS', true),

        /*
         * How many days of delivery attempts to keep. Each attempt stores the
         * envelope it POSTed — which is what makes a manual replay possible —
         * so the log holds a copy of workspace data and is pruned daily. Set to
         * 0 to keep every attempt forever.
         */
        'retention_days' => (int) env('WEBHOOKS_DELIVERY_RETENTION_DAYS', 30),

        /*
         * Seconds to wait before each retry, indexed by the number of prior
         * attempts. The last value is reused once the list is exhausted.
         */
        'backoff' => [10, 30, 120, 600],
    ],

];
