<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Message;
use App\Support\Images\ImageProxy;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The display identity one message asked to be shown under, so a single incoming
 * webhook can post as many logical sources.
 *
 * It rides *beside* a truthful `user`, never replacing it. That is the load-bearing
 * choice: a surface not yet taught about overrides renders the bot's real,
 * admin-controlled identity — it fails closed, where folding the override into
 * `UserData` would have such a surface render an overridden name with no bot
 * marker at all. The rule the rest of the code follows from that: *an overridden
 * name may only render where its bot marker renders with it.*
 *
 * Both fields are individually optional — an override may name only the icon, or
 * only the name — so a caller falls back to the author's own value per field.
 */
#[TypeScript]
class AuthorOverrideData extends Data
{
    public function __construct(
        public ?string $name,
        public ?string $avatar,
    ) {}

    /**
     * Build the override a message carries, or null when it asked for none.
     *
     * The icon is served through {@see ImageProxy} so no reader's IP, user agent
     * or referring page reaches the icon's host and `img-src` keeps its
     * first-party CSP. A stored URL the proxy will not sign resolves to null, and
     * the surface falls back to the author's own avatar.
     */
    public static function forMessage(Message $message): ?self
    {
        if ($message->author_override_name === null && $message->author_override_avatar_url === null) {
            return null;
        }

        return new self(
            name: $message->author_override_name,
            avatar: ImageProxy::url($message->author_override_avatar_url),
        );
    }
}
