<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\HostResolver;
use Closure;
use Override;

/**
 * The one DNS stub the suite uses, so the SSRF guard can be exercised with
 * deterministic addresses instead of real DNS.
 *
 * It replaces six anonymous subclasses that each re-spelled the same two or
 * three lines under a different name (`resolverReturning`, `webhookResolver`,
 * `replayResolver`, `resolveHostsTo`, ...), two of them byte-for-byte identical.
 *
 * Two named readings, for the two questions a test asks of DNS:
 *
 *  - {@see self::returning()} — *this host is at that address*, the fixed answer
 *    almost every test wants.
 *  - {@see self::rebinding()} — *this host answers differently each time it is
 *    asked*, standing in for an authoritative nameserver that rebinds a host
 *    between the guard's check and the connection.
 *
 * Every lookup is recorded, so a test can prove the guard resolved once and
 * pinned rather than letting curl look the host up again.
 */
final class StubHostResolver extends HostResolver
{
    /**
     * The hosts looked up, in the order they were asked for.
     *
     * @var list<string>
     */
    public array $lookups = [];

    /**
     * @param  Closure(string, int): array<int, string>  $answer  Given the host and how many lookups preceded this one, the addresses it resolves to.
     */
    private function __construct(private readonly Closure $answer) {}

    /**
     * A resolver answering each host from a fixed map, and everything else with
     * the same public address.
     *
     * @param  array<string, array<int, string>>  $map
     * @param  array<int, string>  $default
     */
    public static function returning(array $map = [], array $default = ['93.184.216.34']): self
    {
        return new self(static fn (string $host, int $lookup): array => $map[$host] ?? $default);
    }

    /**
     * A resolver answering successive lookups differently, whatever the host.
     * The final answer repeats once the script runs out.
     *
     * @param  non-empty-array<int, array<int, string>>  $answers
     */
    public static function rebinding(array $answers): self
    {
        return new self(static fn (string $host, int $lookup): array => $answers[$lookup] ?? $answers[array_key_last($answers)]);
    }

    /**
     * @return array<int, string>
     */
    #[Override]
    public function resolve(string $host): array
    {
        $lookup = count($this->lookups);
        $this->lookups[] = $host;

        return ($this->answer)($host, $lookup);
    }
}
