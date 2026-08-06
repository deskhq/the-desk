<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\ChannelData;
use App\Data\ChannelReaderData;
use App\Data\MessageData;
use App\Data\ScheduledMessageData;
use App\Data\UserData;
use App\Enums\UserType;
use App\Http\Controllers\Channels\ChannelController;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\Message;
use App\Models\MessagePin;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Everything a channel page renders that is not its timeline: the channel
 * itself, the viewer's standing in it, the controls they may reach for, the
 * pins, the channel's own bots, the read receipts and the composer's pending
 * schedules.
 *
 * It is the other half of ADR-0004. That decision moved the *window* — where a
 * channel's initial page of messages opens — out of {@see ChannelController::show()}
 * and into {@see ChannelTimelineWindow}; the *payload* stayed behind as nineteen
 * query-builder calls, seven `Gate::allows` and three attributes written onto the
 * bound `Channel` so the DTO could read them back off it. This is where it went.
 *
 * Two properties, the same two {@see WorkspaceShell} has:
 *
 * 1. **No request.** The constructor takes a channel and a viewer, so every
 *    reading below is reachable from a test without an HTTP round-trip.
 *    The window is *not* built here and is not a collaborator of this: it reads
 *    `?message=` and `?thread=`, and a read-model that knows about query strings
 *    is the friction this shape exists to avoid. The controller holds both.
 * 2. **No ambient state.** The viewer's own mute, notification level and draft
 *    are read off their membership row and handed to {@see ChannelData::fromChannel()}
 *    as a parameter (ADR-0011), rather than written onto the model for the DTO to
 *    find — which is the same ambient-state defect through a side channel.
 *
 * The page does not name the props it feeds. That list is the Inertia contract
 * and stays in the controller, which is glue: it names the props, resolves the
 * two query params the window takes, and computes none of it.
 */
final class ChannelPage
{
    private bool $membershipResolved = false;

    private ?ChannelMember $membership = null;

    public function __construct(
        private readonly Channel $channel,
        private readonly User $viewer,
    ) {}

    /**
     * The channel as the viewer sees it, carrying their own membership state.
     */
    public function channel(): ChannelData
    {
        return ChannelData::fromChannel($this->channel, $this->viewer, $this->membership());
    }

    /**
     * Whether the viewer already belongs to the channel.
     *
     * A non-member can reach a public channel by URL and read it, but the
     * composer is replaced by a "Join channel" call-to-action until they join.
     */
    public function isMember(): bool
    {
        return $this->membership() instanceof ChannelMember;
    }

    /**
     * The viewer's read pointer, captured before the client's debounced
     * `MarkChannelRead` advances it.
     *
     * Two consumers, which is why it is a reading of its own: it drives the
     * "New messages" divider so it lands at the last-read boundary on open, and
     * it is what anchors {@see ChannelTimelineWindow}'s initial window. Null when
     * the channel has never been read, or was never joined.
     */
    public function lastReadMessageId(): ?string
    {
        $lastReadMessageId = $this->membership()?->last_read_message_id;

        return $lastReadMessageId !== null ? (string) $lastReadMessageId : null;
    }

    /**
     * The controls the page may offer this viewer, as the props that gate them.
     *
     * Seven abilities asked of the policy rather than derived from the viewer's
     * role here, so an affordance and the endpoint behind it can never answer
     * differently. They travel together because they are one question — "what
     * may this person do on this page?" — and because a control added to the
     * masthead should be one entry here, not a ninth `Gate::allows` in a
     * render array.
     *
     * @return array<string, bool>
     */
    public function capabilities(): array
    {
        return [
            // Drives the header's archive control; authoritative so the button
            // only appears for a creator or Admin+ on a non-#general channel.
            'canArchive' => $this->allows('archive'),
            // Gates the notification settings menu; only a member of the channel
            // has preferences to manage.
            'canManagePreferences' => $this->allows('updateMembership'),
            // Gates the channel-details modal's edit mode: any member of a live
            // standard channel may reword its topic and description.
            'canEditChannel' => $this->allows('update'),
            // Narrows that to the name field, which only the creator or a team
            // Admin+ may change.
            'canRenameChannel' => $this->allows('rename'),
            // Drives the header's delete control and its confirmation dialog;
            // only a team Admin+ on a standard, non-#general channel.
            'canDelete' => $this->allows('delete'),
            // Gates the "Leave channel" menu item + modal; a member of a standard
            // channel that isn't #general may leave.
            'canLeave' => $this->allows('leave'),
            // Gates the reaction affordances; only a member of a non-archived
            // channel may react, matching the server-side `postMessage` rule.
            'canReact' => $this->allows('postMessage'),
        ];
    }

    /**
     * How many teammates are in the channel, surfaced in the join
     * call-to-action so a non-member sees who is already there.
     *
     * Bots are integration identities, not seats, so they never count — even
     * though they do appear on the roster below.
     */
    public function memberCount(): int
    {
        return $this->channel->channelMembers()
            ->whereRelation('user', 'type', UserType::Human->value)
            ->count();
    }

    /**
     * The channel's pinned messages and how many there are, as one reading.
     *
     * Most-recently-pinned first, each row a full {@see MessageData} (its own
     * `pin` carries the "Pinned by :name" attribution) so the pins popover reuses
     * the timeline's rendering. Bounded by the 100-pin cap, which is what lets
     * the badge count what the panel holds rather than ask separately: the two
     * are named in `lib/reloadProps` as a pair that must move together, and two
     * independent queries are two answers that can disagree.
     *
     * @return array{pins: array<int, MessageData>, pinCount: int}
     */
    public function pins(): array
    {
        // The pin rows carry the order and the ids; their messages are fetched
        // in one further go through the load-set scope (ADR-0002) rather than
        // joined to. Deleting a message unpins it, so a pin whose message is a
        // tombstone is only ever a race — `Message::query()` withholds it, and
        // it drops out of the list and the count together, which two independent
        // queries could not manage.
        $pins = $this->channel->pins()->get();

        $messages = Message::query()
            ->withMessageDataRelations()
            ->whereKey($pins->pluck('message_id'))
            ->get()
            ->keyBy('id');

        $rows = MessageData::collect(
            $pins->map(fn (MessagePin $pin): ?Message => $messages->get($pin->message_id))->filter()->values(),
            'array',
        );

        return ['pins' => $rows, 'pinCount' => count($rows)];
    }

    /**
     * The only people the page adds to the ones the visit already carries: the
     * channel's own bots.
     *
     * The roster behind the masthead facepile and the composer's `@mention`
     * autocomplete used to ship whole from here, which made it byte-identical to
     * the shell's `teamMembers` on every standard channel — the same team
     * serialised twice in one response (#1254). So the page ships the delta and
     * the client composes: a standard channel is team-scoped (you may mention
     * anyone on the team) plus these bots, which are channel members rather than
     * team members and so appear in no other prop; a direct message is scoped to
     * its own participants, which already ride `channel.dmParticipants`, and so
     * adds nothing at all.
     *
     * Bots appear in the roster (badged) but are filtered out of mention
     * autocomplete client side, a bot having no inbox to reach.
     *
     * @return array<int, UserData>
     */
    public function botMembers(): array
    {
        if ($this->channel->isDirectMessage()) {
            return [];
        }

        $bots = $this->channel->members()
            ->where('users.type', UserType::Bot->value)
            ->orderBy('name')
            ->get();

        return UserData::collect($bots, 'array');
    }

    /**
     * The read pointers of the channel's other members who share read receipts,
     * seeding the "Seen by" affordance at open; later advances arrive over the
     * `MessageRead` broadcast.
     *
     * The viewer is excluded — nobody is shown their own receipt — and so is
     * anyone who opted out of sharing theirs.
     *
     * @return array<int, ChannelReaderData>
     */
    public function readers(): array
    {
        $readers = $this->channel->channelMembers()
            ->where('user_id', '!=', $this->viewer->id)
            ->whereRelation('user', 'share_read_receipts', true)
            ->with('user')
            ->get();

        return ChannelReaderData::collect($readers, 'array');
    }

    /**
     * The viewer's own pending scheduled messages for this channel, soonest
     * first, feeding the composer's "Scheduled" affordance.
     *
     * Only pending rows are listed — sent and cancelled ones drop off — and each
     * one's quote is eager-loaded through the model's own scope, so an
     * inline-reply schedule renders its parent (ADR-0002).
     *
     * @return array<int, ScheduledMessageData>
     */
    public function scheduledMessages(): array
    {
        $scheduled = $this->channel->scheduledMessages()
            ->pending()
            ->where('user_id', $this->viewer->id)
            ->withScheduledMessageDataRelations()
            ->orderBy('send_at')
            ->get();

        return ScheduledMessageData::collect($scheduled, 'array');
    }

    /**
     * Whether the viewer holds an ability on this channel.
     *
     * Asked for the viewer explicitly rather than of the ambient session, which
     * is what keeps every capability above reachable without signing in.
     */
    private function allows(string $ability): bool
    {
        return Gate::forUser($this->viewer)->allows($ability, $this->channel);
    }

    /**
     * The viewer's membership row, or null when they do not belong.
     *
     * Read through {@see ChannelMembership}, the one module that resolves the
     * pivot, and memoized: the channel DTO, the member flag and the read pointer
     * all ask, and a page render should pay for it once.
     */
    private function membership(): ?ChannelMember
    {
        if (! $this->membershipResolved) {
            $this->membership = new ChannelMembership($this->channel, $this->viewer)->row();
            $this->membershipResolved = true;
        }

        return $this->membership;
    }
}
