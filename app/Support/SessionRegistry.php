<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Middleware\TrackActiveSession;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * An owned, per-user index of active sessions.
 *
 * The configured session store (Redis) keeps each session payload under its own
 * key with no way to enumerate a single user's sessions, so device management
 * (listing and revocation) is backed by this index instead. It lives in the
 * application cache — Redis in production, the array store under tests — which
 * makes it independent of `SESSION_DRIVER`: the list and revocation reflect
 * reality regardless of where session payloads are stored.
 *
 * Entries are pruned whenever the index is read or written: any session whose
 * last activity is older than the session lifetime is dropped, and the whole
 * index expires from the cache once the user has been inactive that long.
 *
 * @phpstan-type SessionMeta array{ip_address: ?string, user_agent: ?string, last_activity: int}
 */
class SessionRegistry
{
    public function __construct(private readonly Cache $cache) {}

    /**
     * Record — or refresh the activity of — a session for the given user.
     */
    public function record(string $userId, string $sessionId, ?string $ipAddress, ?string $userAgent, ?int $lastActivity = null): void
    {
        $sessions = $this->load($userId);

        $sessions[$sessionId] = [
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'last_activity' => $lastActivity ?? now()->getTimestamp(),
        ];

        $this->save($userId, $sessions);
    }

    /**
     * List the user's active sessions, most recently active first.
     *
     * @return array<int, array{id: string, ip_address: ?string, user_agent: ?string, last_activity: int}>
     */
    public function all(string $userId): array
    {
        return collect($this->load($userId))
            ->map(fn (array $meta, string $id): array => ['id' => $id, ...$meta])
            ->sortByDesc('last_activity')
            ->values()
            ->all();
    }

    /**
     * Determine whether the given session is still active for the user.
     */
    public function has(string $userId, string $sessionId): bool
    {
        return array_key_exists($sessionId, $this->load($userId));
    }

    /**
     * Revoke a single session, returning whether it was present.
     */
    public function forget(string $userId, string $sessionId): bool
    {
        $sessions = $this->load($userId);

        if (! array_key_exists($sessionId, $sessions)) {
            return false;
        }

        unset($sessions[$sessionId]);
        $this->save($userId, $sessions);

        return true;
    }

    /**
     * Revoke every session except the one to keep, returning the number removed.
     */
    public function forgetOthers(string $userId, string $keepSessionId): int
    {
        $sessions = $this->load($userId);

        $removed = 0;

        foreach (array_keys($sessions) as $id) {
            if ($id !== $keepSessionId) {
                unset($sessions[$id]);
                $removed++;
            }
        }

        if ($removed > 0) {
            $this->save($userId, $sessions);
        }

        return $removed;
    }

    /**
     * Revoke every session for the user, returning the number removed.
     *
     * Used when an account is deactivated (directory deprovisioning): dropping
     * the whole index logs the user out of every device on their next request
     * via {@see TrackActiveSession}.
     */
    public function flush(string $userId): int
    {
        $removed = count($this->load($userId));

        if ($removed > 0) {
            $this->cache->forget($this->key($userId));
        }

        return $removed;
    }

    /**
     * Read the user's index, dropping entries older than the session lifetime.
     *
     * @return array<string, SessionMeta>
     */
    private function load(string $userId): array
    {
        /** @var array<string, SessionMeta> $sessions */
        $sessions = $this->cache->get($this->key($userId), []);

        $threshold = now()->subMinutes($this->lifetime())->timestamp;

        return array_filter($sessions, fn (array $meta): bool => $meta['last_activity'] >= $threshold);
    }

    /**
     * Persist the user's index, or forget it entirely when empty.
     *
     * @param  array<string, SessionMeta>  $sessions
     */
    private function save(string $userId, array $sessions): void
    {
        if ($sessions === []) {
            $this->cache->forget($this->key($userId));

            return;
        }

        $this->cache->put($this->key($userId), $sessions, now()->addMinutes($this->lifetime()));
    }

    /**
     * The cache key holding a user's session index.
     */
    private function key(string $userId): string
    {
        return "active-sessions:{$userId}";
    }

    /**
     * The session lifetime in minutes, floored at one.
     */
    private function lifetime(): int
    {
        return max((int) config('session.lifetime'), 1);
    }
}
