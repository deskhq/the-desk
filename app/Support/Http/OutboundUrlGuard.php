<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Support\HostResolver;

/**
 * Decides whether a member-controlled URL is safe for the server to open a
 * connection to, guarding every outbound fetch against SSRF: a bot-token holder
 * (or settings admin) could otherwise point a webhook subscription at
 * `http://169.254.169.254/` (cloud metadata), `http://localhost/`, or an
 * internal service and have the app POST a signed workspace event straight to
 * it — and the image proxy fetches whatever URL a link preview or Giphy
 * rendition points at.
 *
 * One config knob tunes the guard (see `config/integrations.php`):
 *
 *  - `integrations.webhooks.block_private_urls` (default true) — the master
 *    switch. When false the guard passes everything, for a locked-down
 *    self-hosted instance that deliberately targets internal-only endpoints.
 *    The key kept its webhook-era name so existing `.env` files keep working;
 *    it now governs every outbound fetch, not just webhook delivery.
 *
 * The static {@see self::isPublic()} check blocks by scheme, by literal
 * non-public IP (v4 and v6), and by local hostname — no DNS lookup, so it's
 * deterministic and cheap enough to run in the creation-time validation rule.
 * The delivery-time {@see self::resolveDeliveryIp()} check is the authoritative
 * one: it resolves the hostname and rejects the URL when any resolved address
 * is non-public, returning the vetted IP so the delivery can pin its connection
 * to it (closing the DNS-rebinding window between validation and connect).
 */
class OutboundUrlGuard
{
    public function __construct(private readonly HostResolver $resolver) {}

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
     * Resolve the URL's host for delivery and decide whether to connect.
     *
     * Returns the vetted public IP to pin the connection to when the host is a
     * hostname, `null` when there is nothing to pin (the guard is disabled, or
     * the host is a literal IP already vetted by {@see self::isPublic()}), and
     * `false` when the delivery must be blocked (the host does not resolve, or
     * any resolved address is private/reserved).
     */
    public function resolveDeliveryIp(string $url): string|false|null
    {
        if (! (bool) config('integrations.webhooks.block_private_urls', true)) {
            return null;
        }

        $host = trim((string) (parse_url($url, PHP_URL_HOST) ?? ''), '[]');

        if ($host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $ips = $this->resolver->resolve($host);

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return false;
            }
        }

        return $ips[0];
    }

    /**
     * Guzzle options for an outbound request the guard has vetted: redirects
     * disabled (a redirect would bounce the request onto a target the guard
     * never saw), and — when the guard resolved a hostname — the connection
     * pinned to the vetted IP so a DNS rebind between the check and the connect
     * can't retarget it either.
     *
     * @param  string|null  $pinnedIp  the value {@see self::resolveDeliveryIp()} returned
     * @return array<string, mixed>
     */
    public function transportOptions(string $url, ?string $pinnedIp): array
    {
        $options = ['allow_redirects' => false];

        if ($pinnedIp === null) {
            return $options;
        }

        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? '');
        $port = (int) ($parts['port'] ?? (strtolower((string) ($parts['scheme'] ?? '')) === 'http' ? 80 : 443));

        $address = str_contains($pinnedIp, ':') ? '['.$pinnedIp.']' : $pinnedIp;

        $options['curl'] = [CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, $address)]];

        return $options;
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
