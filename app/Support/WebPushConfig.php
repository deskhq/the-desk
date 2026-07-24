<?php

declare(strict_types=1);

namespace App\Support;

final class WebPushConfig
{
    /**
     * Whether this instance can send web push at all.
     *
     * Push is signed with a VAPID keypair the operator generates once (see
     * `php artisan webpush:vapid`), so without both halves there is nothing to
     * sign with and no subscription a browser would accept. Every push surface
     * keys on this: the settings toggle hides, the subscribe endpoints 404, and
     * the fan-out never queues a notification.
     */
    public static function configured(): bool
    {
        return filled(config('webpush.vapid.public_key'))
            && filled(config('webpush.vapid.private_key'));
    }

    /**
     * The browser-facing push details, served to the SPA at runtime (via an
     * Inertia shared prop) rather than baked into the JS bundle at build time —
     * the same reason {@see ReverbConfig::forFrontend()} exists: one published
     * image has to work for any operator's keypair without a rebuild.
     *
     * The public key is what `pushManager.subscribe()` needs; the private key
     * never leaves the server. Both are withheld when push is unconfigured, so
     * the frontend cannot offer a toggle that could only fail.
     *
     * @return array{enabled: bool, publicKey: string|null}
     */
    public static function forFrontend(): array
    {
        if (! self::configured()) {
            return ['enabled' => false, 'publicKey' => null];
        }

        return ['enabled' => true, 'publicKey' => (string) config('webpush.vapid.public_key')];
    }
}
