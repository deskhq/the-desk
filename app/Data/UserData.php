<?php

namespace App\Data;

use App\Enums\PresenceState;
use App\Models\User;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class UserData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $avatar = null,
        // Whether this author is a bot, so every surface that renders a user
        // (message rows, the channel member facepile) can mark it with the bot
        // badge and squared avatar, and the composer can exclude it from @mention
        // autocomplete.
        public bool $isBot = false,
        // The author's live custom status, so every surface that renders a user
        // can show the emoji inline beside their name. Null when they have set
        // none or theirs has lapsed, and the surface then renders nothing.
        public ?UserStatusData $status = null,
        // How reachable the author is, composed with the Reverb roster on the
        // client: the roster answers "connected at all", this refines a
        // connected user into active or away. Carried in the initial props so a
        // freshly loaded client — which has no broadcast history — still paints
        // the right dot before any UserPresenceChanged event arrives.
        public PresenceState $presence = PresenceState::Active,
        // Whether the author is in do-not-disturb right now, so the DND badge
        // rides every presence-dot surface. A bare boolean on purpose: the
        // pause instant and the quiet-hours schedule are the owner's business
        // and never leave their own `auth.user` prop.
        public bool $isDnd = false,
    ) {}

    /**
     * Build the DTO from a User model.
     */
    public static function fromUser(User $user): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            avatar: $user->avatar,
            isBot: $user->isBot(),
            status: UserStatusData::forUser($user),
            presence: $user->effectivePresence(),
            isDnd: $user->isDndActive(),
        );
    }
}
