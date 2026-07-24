<?php

use NotificationChannels\WebPush\PushSubscription;

return [

    /*
    |--------------------------------------------------------------------------
    | VAPID Keys
    |--------------------------------------------------------------------------
    |
    | The keypair every push message is signed with, identifying this instance
    | to the browser vendors' push services. Generate one per instance with
    | `php artisan webpush:vapid` and keep it stable: the public key is baked
    | into each browser's subscription, so rotating it invalidates every
    | subscription users have already granted. Web push stays switched off
    | until both keys are set — see App\Support\WebPushConfig.
    |
    | The subject identifies the operator to the push service (a mailto: or an
    | https: URL); it falls back to the instance's own URL when unset.
    |
    */

    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'pem_file' => env('VAPID_PEM_FILE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription Storage
    |--------------------------------------------------------------------------
    |
    | One row per browser that has granted permission. The connection is left
    | null so the table lives on the app's default connection, whatever the
    | operator configured it to be.
    |
    */

    'model' => PushSubscription::class,

    'table_name' => 'push_subscriptions',

    'database_connection' => null,

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    |
    | Guzzle options for the outbound calls to the push services, and the
    | automatic payload padding that hides a message's true length from them.
    |
    */

    'client_options' => [],

    'automatic_padding' => env('WEBPUSH_AUTOMATIC_PADDING', true),

];
