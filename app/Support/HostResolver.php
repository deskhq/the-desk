<?php

declare(strict_types=1);

namespace App\Support;

class HostResolver
{
    /**
     * Resolve a host to the IP addresses it points at.
     *
     * A literal IP resolves to itself; a hostname is resolved to its A records.
     * Bound in the container so the SSRF guard can be exercised in tests with
     * deterministic addresses instead of real DNS.
     *
     * @return array<int, string>
     */
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return gethostbynamel($host) ?: [];
    }
}
