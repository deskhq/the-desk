<?php

namespace App\Data;

use App\Enums\NotificationLevel;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\User;
use App\Support\ChannelPage;
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
     * `$membership` is the viewer's own row on the channel, and it is where their
     * per-channel state — mute, notification level, draft, star — is read from
     * when the caller already holds it: the channel page passes the row
     * {@see ChannelPage} resolved, so nothing has to be written onto the model to
     * smuggle it in. A caller with no row to hand over passes none, and a viewer
     * with no row at all — a non-member reading a public channel — gets the
     * defaults (unmuted, "all", no draft, unstarred).
     *
     * The sidebar is the one caller that passes none and still carries state: its
     * single query selects every pivot column onto each channel, so those
     * attributes stand in for the row rather than costing one query per row.
     *
     * What this DTO deliberately does *not* carry is what is unread in the
     * channel. A roster changes when someone renames or joins something; unread
     * state changes on every message anyone sends, and welding the two together
     * is why the whole list used to be rebuilt on every navigation to move four
     * integers. The badges now ride {@see UnreadDigestData}, which
     * still applies mute and the notification level server-side. `muted` and
     * `notificationLevel` stay here because they are the viewer's own standing
     * preference — what the row is *set to*, not what is waiting in it.
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
    public static function fromChannel(Channel $channel, User $viewer, ?ChannelMember $membership = null): self
    {
        // A handed-over row answers for the whole membership, attributes for the
        // whole of it, or neither — never half of each, so a null column on the
        // row reads as "not set" rather than falling through to a stale attribute.
        $stated = $membership instanceof ChannelMember;

        $muted = $stated ? $membership->muted : (bool) ($channel->getAttribute('muted') ?? false);

        $level = $stated
            ? $membership->notification_level
            : NotificationLevel::tryFrom((string) ($channel->getAttribute('notification_level') ?? NotificationLevel::All->value)) ?? NotificationLevel::All;

        $draftText = $stated ? $membership->draft : $channel->getAttribute('draft');
        $draft = is_string($draftText) && trim($draftText) !== '' ? $draftText : null;

        // The sidebar ships a `has_draft` flag in place of the text it withholds;
        // everywhere else the presence of the draft is the answer.
        $hasDraftAttribute = $stated ? null : $channel->getAttribute('has_draft');
        $hasDraft = $hasDraftAttribute !== null ? (bool) $hasDraftAttribute : $draft !== null;

        $starred = $stated ? $membership->starred : (bool) ($channel->getAttribute('starred') ?? false);

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
