<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Middleware\HandleInertiaRequests;

/**
 * A short digest of the instance configuration the shell's constant props are
 * drawn from, used as the invalidation key those props are shared under.
 *
 * They are constants of the deployment rather than of the request — the app's
 * name, the branding, the Reverb and web-push connection details, the feature
 * toggles — so a client that has them once has them until the operator changes
 * one. This digest is what "until the operator changes one" means: every value
 * below is already in memory when the request starts, so recomputing it per
 * request costs a hash over a handful of scalars, and a changed value yields a
 * different key, which reships the whole group on the client's next visit
 * without a hard reload.
 *
 * Two deliberate properties:
 *
 * 1. **One digest for the whole group, not one per prop.** They change
 *    together — an operator edits `.env` and restarts — so a per-prop digest
 *    would buy nothing and cost fifteen more hashes.
 * 2. **The logo is not in it.** Resolving whether the operator has supplied one
 *    is a filesystem stat, and keeping that on the per-request path is precisely
 *    what {@see HandleInertiaRequests::shareOnce()} exists to stop. A logo
 *    dropped onto a running instance therefore reaches clients on their next
 *    hard load, which is accepted.
 *
 * Over-invalidating is safe and under-invalidating is not, so the sources are
 * read generously: `app.version` stands in for everything that is a code
 * constant rather than a config value (the channel restore window, the role and
 * sidebar-position enums), since those can only change with a deploy.
 */
final class InstanceFingerprint
{
    /**
     * The config keys the instance-config props read, and nothing else.
     *
     * Kept in step with the group by `SharedOncePropsTest`, which changes each
     * one and asserts the props come back.
     *
     * @var list<string>
     */
    private const array SOURCES = [
        'app.name',
        'app.version',
        'branding.attribution',
        'broadcasting.connections.reverb',
        'webpush.vapid.public_key',
        'webpush.vapid.private_key',
        'fortify.features',
        'fortify.email_verification_enabled',
        'demo.mode',
        'sso.oidc.enabled',
        'sso.enforced',
        'attachments.max_size_mb',
        'attachments.max_per_message',
        'services.giphy.key',
        'polls.enabled',
        'integrations.enabled',
        'presence.away_after_minutes',
    ];

    /**
     * How much of the digest is kept.
     *
     * It is carried in a request header once per prop in the group, so its
     * length is paid on every navigation while the saving it buys is paid once.
     * Thirty-two bits is ample for the job: a collision does not corrupt
     * anything, it only means one config change reaches clients on their next
     * hard load rather than their next visit.
     */
    private const int LENGTH = 8;

    /**
     * The digest of this instance's configuration as it stands.
     */
    public static function current(): string
    {
        $values = array_map(static fn (string $key): mixed => config($key), self::SOURCES);

        return substr(hash('xxh128', (string) json_encode($values)), 0, self::LENGTH);
    }
}
