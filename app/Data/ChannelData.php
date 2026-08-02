<?php

namespace App\Data;

use App\Enums\NotificationLevel;
use App\Models\Channel;
use App\Models\User;
use App\Support\DirectMessageRoster;
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
        /** The long-form "what this channel is for", rendered with links and basic Markdown. */
        public ?string $description,
        public bool $isGeneral,
        public bool $isArchived,
        public bool $muted = false,
        public NotificationLevel $notificationLevel = NotificationLevel::All,
        public int $unreadCount = 0,
        public int $mentionCount = 0,
        public bool $hasDraft = false,
        public ?string $draft = null,
        public bool $starred = false,
        public ?string $sectionId = null,
        public int $position = 0,
        public bool $isDirect = false,
        public bool $isGroupDirect = false,
        public ?string $dmUserId = null,
        /** @var array<int, UserData>|null */
        public ?array $dmParticipants = null,
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
     *
     * The viewer is a parameter, never read from the ambient session: this is a
     * pure mapping, so it can be built for anyone — which is what makes it
     * testable without signing in, and what keeps `app/Data` free of the
     * authenticated-user helper entirely (ADR-0011 — whose guard is a grep for
     * that helper's name, so this paragraph does not spell it). Batch a page of
     * channels through {@see DirectMessageRoster} first and naming their direct
     * messages then costs this no query at all.
     */
    public static function fromChannel(Channel $channel, User $viewer): self
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

        // DMs have no stored name; they render viewer-relative. A 1:1 shows the
        // other participant (themselves, labelled "You" by the client, in a
        // self-DM), keyed for presence + avatar by `dmUserId`. A group DM shows
        // the other participants as an avatar stack + a comma/ampersand-joined
        // name the client formats from `dmParticipants`; `name` carries a
        // full-name join as an accessible fallback. Standard channels keep their
        // own `name`. All of that is one rule with three consumers, so it is the
        // model's to answer, not this DTO's to re-derive.
        $isDirectMessage = $channel->isDirectMessage();

        // `dmParticipants` is populated for every DM (the client keys its avatar
        // stack, participant name, and same-member-set dedup off it): the roster
        // minus the viewer, empty for a self-DM whose only member is the viewer.
        // Sorted the way `displayNameFor()` sorts the name it joins from the same
        // people, so the stack and the label can never disagree on their order.
        $participants = $isDirectMessage
            ? $channel->members
                ->reject(fn (User $member): bool => $member->id === $viewer->id)
                ->sortBy('name')
                ->map(fn (User $member): UserData => UserData::fromUser($member))
                ->values()
                ->all()
            : null;

        // The timestamp the "Direct messages" sidebar group orders on: the
        // channel's latest message (populated by the sidebar query as
        // `last_message_at`), falling back to when the channel itself was created
        // so a never-messaged DM the creator opened still sorts sensibly.
        $lastMessageAt = $channel->getAttribute('last_message_at');
        $lastActivityAt = $lastMessageAt !== null ? Carbon::parse($lastMessageAt)->toISOString() : $channel->created_at?->toISOString();

        return new self(
            id: $channel->id,
            name: $channel->displayNameFor($viewer),
            slug: $channel->slug,
            visibility: $channel->visibility->value,
            topic: $channel->topic,
            description: $channel->description,
            isGeneral: $channel->isGeneral(),
            isArchived: $channel->isArchived(),
            muted: $muted,
            notificationLevel: $level,
            unreadCount: $level->alertsOnUnread($muted) ? $unreadCount : 0,
            mentionCount: $level->alertsOnMention($muted) ? $mentionCount : 0,
            hasDraft: $hasDraft,
            draft: $draft,
            starred: $starred,
            sectionId: $sectionId,
            position: $position,
            isDirect: $isDirectMessage,
            isGroupDirect: $channel->isGroupDirect(),
            dmUserId: $channel->directParticipantFor($viewer)?->id,
            dmParticipants: $participants,
            lastActivityAt: $lastActivityAt,
        );
    }
}
