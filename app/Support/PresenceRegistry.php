<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\PresenceState;
use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * An owned, per-user index of live browser connections and how active each one is.
 *
 * Presence metadata on a Reverb roster is frozen at join and cannot be mutated
 * without leaving and rejoining, so a mid-session active↔away flip has nowhere
 * to live there. This index is that home: each of a user's tabs reports itself
 * under its own key, and the aggregate is derived server-side so a client that
 * has just loaded (and so has no event history) still gets the right answer in
 * its initial props.
 *
 * It mirrors {@see SessionRegistry}'s cache-backed shape but is deliberately a
 * separate class: sessions are the grain device management needs, and two tabs
 * of one session can disagree about being idle.
 *
 * The feature fails open. A user with no entry at all — an evicted cache key, a
 * client that has not reported yet, an unavailable Redis — reads as active,
 * which is exactly the behaviour that predates this index.
 *
 * @phpstan-type ConnectionMeta array{state: string, last_seen: int}
 */
class PresenceRegistry
{
    /**
     * Aggregates already resolved this request, keyed by user id.
     *
     * A single render asks for the same author's presence once per message row,
     * so the cache round-trip is made once per user per request.
     *
     * @var array<string, PresenceState>
     */
    private array $resolved = [];

    public function __construct(private readonly Cache $cache) {}

    /**
     * Record — or refresh — how active one of a user's connections is.
     */
    public function record(string $userId, string $connectionId, PresenceState $state): void
    {
        $connections = $this->load($userId);

        $connections[$connectionId] = [
            'state' => $state->value,
            'last_seen' => now()->getTimestamp(),
        ];

        $this->save($userId, $connections);
    }

    /**
     * Drop a connection, e.g. when its tab is closed.
     */
    public function forget(string $userId, string $connectionId): void
    {
        $connections = $this->load($userId);

        if (! array_key_exists($connectionId, $connections)) {
            return;
        }

        unset($connections[$connectionId]);

        $this->save($userId, $connections);
    }

    /**
     * The state teammates should see for a connected user.
     *
     * Away only once every reporting connection is idle: one active laptop
     * keeps the user active however many phones have gone to sleep.
     */
    public function aggregate(string $userId): PresenceState
    {
        return $this->resolved[$userId] ??= $this->derive($userId);
    }

    /**
     * Resolve a user's aggregate from their live connections.
     */
    private function derive(string $userId): PresenceState
    {
        $connections = $this->load($userId);

        if ($connections === []) {
            return PresenceState::Active;
        }

        foreach ($connections as $meta) {
            if ($meta['state'] === PresenceState::Active->value) {
                return PresenceState::Active;
            }
        }

        return PresenceState::Away;
    }

    /**
     * Read a user's connections, dropping any that stopped reporting.
     *
     * @return array<string, ConnectionMeta>
     */
    private function load(string $userId): array
    {
        try {
            /** @var array<string, ConnectionMeta> $connections */
            $connections = $this->cache->get($this->key($userId), []);
        } catch (Throwable $exception) {
            // Failing open is the whole contract: a dot is not worth 500ing a
            // page render over, and an unreachable cache reads as "no idle
            // connections", which is exactly the pre-feature behaviour.
            report($exception);

            return [];
        }

        $threshold = now()->subMinutes($this->ttlMinutes())->getTimestamp();

        return array_filter($connections, fn (array $meta): bool => $meta['last_seen'] >= $threshold);
    }

    /**
     * Persist a user's connections, or forget the key entirely when none remain.
     *
     * @param  array<string, ConnectionMeta>  $connections
     */
    private function save(string $userId, array $connections): void
    {
        unset($this->resolved[$userId]);

        try {
            if ($connections === []) {
                $this->cache->forget($this->key($userId));

                return;
            }

            $this->cache->put($this->key($userId), $connections, now()->addMinutes($this->ttlMinutes()));
        } catch (Throwable $exception) {
            // A dropped write is recoverable on its own: every connection
            // re-states itself on its next heartbeat.
            report($exception);
        }
    }

    /**
     * How long a connection survives without reporting.
     *
     * Derived from the one operator-facing knob rather than adding a second:
     * clients heartbeat well inside this window, so the margin above the idle
     * threshold only has to outlast a missed beat. A crashed tab is cleaned up
     * once the margin lapses — the `pagehide` release handles every ordinary
     * close instantly.
     */
    private function ttlMinutes(): int
    {
        return max((int) config('presence.away_after_minutes'), 1) + 5;
    }

    /**
     * The cache key holding a user's connection index.
     */
    private function key(string $userId): string
    {
        return "presence-connections:{$userId}";
    }
}
