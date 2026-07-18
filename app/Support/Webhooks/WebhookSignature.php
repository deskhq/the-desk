<?php

declare(strict_types=1);

namespace App\Support\Webhooks;

/**
 * Builds the `X-Desk-Signature` header value for an outgoing webhook.
 *
 * The scheme is timestamped HMAC-SHA256 (the convention Stripe popularised): the
 * signed message is `"{timestamp}.{raw body}"`, so a receiver recomputes the
 * HMAC over the exact bytes it received and compares in constant time, and can
 * reject a stale timestamp to defend against replay. The header carries both the
 * timestamp and the digest: `t=<unix ts>,v1=<hex hmac>`.
 */
class WebhookSignature
{
    /**
     * Build the signature header value for a payload signed at a given time.
     */
    public static function header(string $secret, string $payload, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.self::digest($secret, $payload, $timestamp);
    }

    /**
     * Compute the hex HMAC-SHA256 of `"{timestamp}.{payload}"` under the secret.
     */
    public static function digest(string $secret, string $payload, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }
}
