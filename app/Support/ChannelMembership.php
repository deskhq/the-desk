<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\NotificationLevel;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\User;

/**
 * One user's membership of one channel: the pivot row, and every mutation of it.
 *
 * Star, mute, notification level, draft, close (hide) and sidebar placement are
 * not five unrelated settings — they are the columns of a single row, and this
 * is the module that owns writing them. It is constructed from a `Channel` and a
 * `User`, never a `Request`, so every write is reachable from a test without an
 * HTTP round-trip.
 *
 * **Resolve-or-no-op.** Every write targets the caller's own row and only that
 * row, and a user with no membership writes nothing at all rather than
 * conjuring one — asking to mute a channel you do not belong to is a request
 * with no subject, not a request to join. That semantic is the reason the
 * behaviour lives here rather than as instance methods on {@see ChannelMember}:
 * a method on a row you failed to load cannot express it.
 */
final class ChannelMembership
{
    private bool $resolved = false;

    private ?ChannelMember $row = null;

    public function __construct(
        private readonly Channel $channel,
        private readonly User $user,
    ) {}

    /**
     * The membership row, or null when the user does not belong to the channel.
     *
     * Memoized: the shell reads it once per request to seed the channel view's
     * preferences, draft and read cursor, and a second caller in the same request
     * should not pay for a second query.
     */
    public function row(): ?ChannelMember
    {
        if (! $this->resolved) {
            $this->row = $this->channel->channelMembers()->firstWhere('user_id', $this->user->id);
            $this->resolved = true;
        }

        return $this->row;
    }

    /**
     * Whether the user belongs to the channel.
     */
    public function exists(): bool
    {
        return $this->row() instanceof ChannelMember;
    }

    /**
     * Set the user's star (favorite) flag for the channel.
     */
    public function star(bool $starred): void
    {
        $this->write(['starred' => $starred]);
    }

    /**
     * Persist the user's notification preferences for the channel.
     *
     * The mute flag and the level are one setting in two columns — the settings
     * menu writes both on every save — so they are written together.
     */
    public function setNotificationPreference(bool $muted, NotificationLevel $notificationLevel): void
    {
        $this->write([
            'muted' => $muted,
            'notification_level' => $notificationLevel->value,
        ]);
    }

    /**
     * Persist the user's unsent composer text for the channel.
     *
     * The text is stored verbatim (mention tokens and all) so it restores
     * faithfully. A blank draft — empty or whitespace-only — is stored as null
     * instead, so it clears the sidebar's pending-draft cue rather than lingering
     * as an "empty" draft. That rule lives here and nowhere else.
     */
    public function saveDraft(?string $draft): void
    {
        $this->write([
            'draft' => $draft !== null && trim($draft) !== '' ? $draft : null,
        ]);
    }

    /**
     * Consume the user's draft for the channel, because its text has been sent.
     *
     * Both an immediate send and a scheduled one spend the composer's text, so
     * both clear the draft through here rather than writing the pivot themselves.
     */
    public function clearDraft(): void
    {
        $this->write(['draft' => null]);
    }

    /**
     * Close (hide) the channel from the user's own sidebar.
     *
     * Stamps the row with the current time; the sidebar's listing predicate then
     * drops a direct message until a message arrives after this instant. Only the
     * caller's row is stamped, so each side of a conversation closes it
     * independently.
     */
    public function hide(): void
    {
        $this->write(['hidden_at' => now()]);
    }

    /**
     * Re-open a conversation the user had closed, without waiting for a message
     * to arrive — what opening a direct message again does for its initiator.
     */
    public function unhide(): void
    {
        $this->write(['hidden_at' => null]);
    }

    /**
     * Place the channel within the user's sidebar: file it under a section
     * and/or reorder the group it now lives in.
     *
     * `$orderedIds` is the full, ordered list of channel ids in the target group;
     * each of the user's matching memberships takes its index as the new
     * position, so a drag persists the whole group's order in one write. Ids for
     * channels they don't belong to — or in another team — are ignored, the ACL
     * coming from {@see User::visibleChannelIds()} rather than being re-derived
     * here.
     *
     * When `$moveSection` is true the channel's `section_id` is set to
     * `$sectionId` (null for the default "Channels" group); a pure within-group
     * reorder leaves the assignment untouched.
     *
     * @param  list<string>  $orderedIds
     */
    public function place(array $orderedIds, bool $moveSection, ?string $sectionId): void
    {
        ManualOrder::apply(
            ChannelMember::query()
                ->where('user_id', $this->user->id)
                ->whereIn('channel_id', $this->user->visibleChannelIds($this->channel->team)),
            'channel_id',
            $orderedIds,
        );

        if ($moveSection) {
            $this->write(['section_id' => $sectionId]);
        }
    }

    /**
     * Drop the user's membership of the channel, answering whether there was one
     * to drop.
     *
     * The answer is what separates a removal that happened from a repeat of one
     * that already had: only the former is an administrative act worth auditing.
     */
    public function remove(): bool
    {
        $removed = $this->channel->channelMembers()->where('user_id', $this->user->id)->delete();

        $this->resolved = false;
        $this->row = null;

        return $removed > 0;
    }

    /**
     * Write the given columns to the user's own membership row.
     *
     * `updateExistingPivot` carries the resolve-or-no-op rule in one statement:
     * a non-member matches nothing and nothing is written. The memoized row is
     * dropped so a read after a write sees the new state.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function write(array $attributes): void
    {
        $this->user->channels()->updateExistingPivot($this->channel->id, $attributes);

        $this->resolved = false;
        $this->row = null;
    }
}
