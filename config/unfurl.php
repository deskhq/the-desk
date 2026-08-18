<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Link unfurling
    |--------------------------------------------------------------------------
    |
    | Posting a URL queues a background unfurl that fetches the page and reads
    | its Open Graph tags. That fetch is the one thing this application makes on
    | a member's say-so, so it does not happen here: it happens in the `unfurler`
    | service, which holds no application state, publishes no host port, and
    | vets every destination on the connect path. See dev-docs/adr/0016.
    |
    */

    /*
     * Where the unfurl service lives, on the internal container network.
     *
     * Unset turns link unfurling off entirely: pending previews resolve to
     * Failed, no card renders, and no request is made. That is what an operator
     * running a customised compose file without the `unfurler` service gets, and
     * it is a legitimate configuration in its own right for an instance that
     * wants no outbound fetching at all.
     */
    'url' => env('UNFURLER_URL'),

    /*
     * The shared secret the service checks on every request.
     *
     * Its own secret rather than something derived from APP_KEY, deliberately:
     * a derivation would have to be computable on both sides, which means
     * handing APP_KEY to the one container built to hold nothing. Generated per
     * instance by docker/gen-secrets.sh.
     */
    'token' => env('UNFURLER_TOKEN'),

    /*
     * Per-request budget, in seconds. Deliberately above the service's own batch
     * budget so the *service* is what decides a slow page has taken too long;
     * this only trips on a container that has stopped answering at all.
     */
    'timeout' => (int) env('UNFURLER_TIMEOUT', 15),

    /*
     * How long to wait for the connection itself. A container on the same
     * compose network answers in single-digit milliseconds or is not there.
     */
    'connect_timeout' => 2,

    /*
     * How long a resolved (or failed) unfurl stays cached per URL, so the same
     * link shared across many messages is only fetched once.
     *
     * This caches what the service *decided*, never a failure to reach it: a
     * five-minute outage must not poison a day of link previews.
     */
    'cache_ttl' => 86400,

];
