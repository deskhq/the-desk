<?php

declare(strict_types=1);

namespace App\Support\Webhooks;

/**
 * Decides whether a webhook destination URL is safe to deliver to, guarding the
 * outgoing-webhook feature against SSRF: a bot-token holder (or settings admin)
 * could otherwise point a subscription at `http://169.254.169.254/` (cloud
 * metadata), `http://localhost/`, or an internal service and have the app POST a
 * signed workspace event straight to it.
 *
 * One config knob tunes the guard (see `config/integrations.php`):
 *
 *  - `integrations.webhooks.block_private_urls` (default true) — the master
 *    switch. When false the guard passes everything, for a locked-down
 *    self-hosted instance that deliberately targets internal-only endpoints.
 *
 * The guard blocks by scheme, by literal non-public IP (v4 and v6), and by
 * local hostname — no DNS lookup, so it's deterministic and covers the common
 * SSRF targets (the cloud-metadata endpoint is a literal IP, loopback and
 * private ranges are literals, and `localhost`/`.local`/`.internal` are named).
 * It does not resolve arbitrary hostnames, so a public name that an attacker
 * points at a private address via DNS is out of scope here — front that with
 * network egress rules if it matters for your deployment.
 */
class WebhookUrlGuard
{
    /**
     * Host names that always resolve to the local machine, rejected by name.
     *
     * @var list<string>
     */
    private const array BLOCKED_HOSTS = ['localhost', 'ip6-localhost', 'ip6-loopback'];

    /**
     * Host suffixes reserved for local/private use (RFC 6762 `.local`, and the
     * conventional `.internal` / `.localhost` names).
     *
     * @var list<string>
     */
    private const array BLOCKED_SUFFIXES = ['.localhost', '.local', '.internal'];

    /**
     * Whether the given URL is a public http/https destination the app may
     * deliver a webhook to.
     */
    public static function isPublic(string $url): bool
    {
        if (! (bool) config('integrations.webhooks.block_private_urls', true)) {
            return true;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = trim($parts['host'], '[]');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIp($host);
        }

        return ! self::isBlockedHost($host);
    }

    /**
     * Whether an IP address sits in a publicly-routable range (rejecting private,
     * loopback, link-local, and other reserved blocks in both IPv4 and IPv6).
     */
    private static function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * Whether a hostname is a known local/private name that needs no DNS lookup.
     */
    private static function isBlockedHost(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            return true;
        }

        foreach (self::BLOCKED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
