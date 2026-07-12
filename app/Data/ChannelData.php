<?php

namespace App\Data;

use App\Enums\NotificationLevel;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Carbon;
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
        public bool $hasDraft = false,
        public ?string $draft = null,
        public bool $starred = false,
        public ?string $sectionId = null,
        public int $position = 0,
        public bool $isDirect = false,
        public ?string $dmUserId = null,
        public ?string $lastActivityAt = null,
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
     *
     * The draft is the viewer's own pending composer text. The open channel
     * (`Show`) carries the full `draft` so the composer restores it; the sidebar
     * ships only the `has_draft` boolean, so `draft` stays null there and only
     * the presence cue is exposed.
     *
     * `starred` is the viewer's own favorite flag, driving whether the channel is
     * pinned to the sidebar's "Starred" section.
     *
     * `sectionId` is the custom section the viewer has filed the channel under
     * (null for the default "Channels" group), and `position` is its manual order
     * within whichever group it renders in.
     */
    public static function fromChannel(Channel $channel): self
    {
        $muted = (bool) ($channel->getAttribute('muted') ?? false);

        $level = NotificationLevel::tryFrom((string) ($channel->getAttribute('notification_level') ?? NotificationLevel::All->value)) ?? NotificationLevel::All;

        $unreadCount = (int) ($channel->getAttribute('unread_count') ?? 0);
        $mentionCount = (int) ($channel->getAttribute('mention_count') ?? 0);

        $draftText = $channel->getAttribute('draft');
        $draft = is_string($draftText) && trim($draftText) !== '' ? $draftText : null;

        $hasDraftAttribute = $channel->getAttribute('has_draft');
        $hasDraft = $hasDraftAttribute !== null ? (bool) $hasDraftAttribute : $draft !== null;

        $starred = (bool) ($channel->getAttribute('starred') ?? false);

        $sectionId = $channel->getAttribute('section_id');
        $sectionId = is_string($sectionId) ? $sectionId : null;

        $position = (int) ($channel->getAttribute('position') ?? 0);

        // DMs have no stored name; they render viewer-relative — the current user
        // sees the other participant (themselves, labelled "You" by the client, in
        // a self-DM). `dmUserId` lets the sidebar key presence and the avatar off
        // that participant. Standard channels keep their own `name`.
        $viewer = auth()->user();
        $isDirect = $channel->isDirect();
        $dmParticipant = $isDirect && $viewer instanceof User ? $channel->directParticipantFor($viewer) : null;
        $name = $dmParticipant instanceof User ? $dmParticipant->name : (string) $channel->name;

        // The timestamp the "Direct messages" sidebar group orders on: the
        // channel's latest message (populated by the sidebar query as
        // `last_message_at`), falling back to when the channel itself was created
        // so a never-messaged DM the creator opened still sorts sensibly.
        $lastMessageAt = $channel->getAttribute('last_message_at');
        $lastActivityAt = $lastMessageAt !== null ? Carbon::parse($lastMessageAt)->toISOString() : $channel->created_at?->toISOString();

        return new self(
            id: $channel->id,
            name: $name,
            slug: $channel->slug,
            visibility: $channel->visibility->value,
            topic: $channel->topic,
            isGeneral: $channel->isGeneral(),
            isArchived: $channel->isArchived(),
            muted: $muted,
            notificationLevel: $level->value,
            unreadCount: ! $muted && $level->alertsOnUnread() ? $unreadCount : 0,
            mentionCount: ! $muted && $level->alertsOnMention() ? $mentionCount : 0,
            hasDraft: $hasDraft,
            draft: $draft,
            starred: $starred,
            sectionId: $sectionId,
            position: $position,
            isDirect: $isDirect,
            dmUserId: $dmParticipant?->id,
            lastActivityAt: $lastActivityAt,
        );
    }
}
