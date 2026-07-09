<?php

namespace App\Data;

use App\Enums\NotificationLevel;
use App\Models\Channel;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ChannelData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public string $visibility,
        public ?string $topic,
        public bool $isGeneral,
        public bool $isArchived,
        public bool $muted = false,
        public string $notificationLevel = NotificationLevel::All->value,
        public int $unreadCount = 0,
        public int $mentionCount = 0,
    ) {}

    /**
     * Build the DTO from a Channel model.
     *
     * `unread_count`, `mention_count`, `muted` and `notification_level` are the
     * current user's per-channel state, populated only when the channel was
     * loaded for their sidebar or view; elsewhere they are absent and fall back
     * to the defaults (unmuted, "all", zero badges).
     *
     * The badge counts are suppressed here so the sidebar prop is authoritative:
     * a muted channel or the "nothing" level shows no badge at all, and the
     * "mentions" level keeps only the mention badge (a direct @mention still
     * alerts while ordinary unread traffic is silenced).
     */
    public static function fromChannel(Channel $channel): self
    {
        $muted = (bool) ($channel->getAttribute('muted') ?? false);

        $level = NotificationLevel::tryFrom((string) ($channel->getAttribute('notification_level') ?? NotificationLevel::All->value)) ?? NotificationLevel::All;

        $unreadCount = (int) ($channel->getAttribute('unread_count') ?? 0);
        $mentionCount = (int) ($channel->getAttribute('mention_count') ?? 0);

        return new self(
            id: $channel->id,
            name: $channel->name,
            slug: $channel->slug,
            visibility: $channel->visibility->value,
            topic: $channel->topic,
            isGeneral: $channel->isGeneral(),
            isArchived: $channel->isArchived(),
            muted: $muted,
            notificationLevel: $level->value,
            unreadCount: ! $muted && $level->alertsOnUnread() ? $unreadCount : 0,
            mentionCount: ! $muted && $level->alertsOnMention() ? $mentionCount : 0,
        );
    }
}
