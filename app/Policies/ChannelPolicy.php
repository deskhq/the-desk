<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ChannelVisibility;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;

final class ChannelPolicy
{
    /**
     * Determine whether the user can create a channel in the team.
     *
     * The workspace holds one creation policy per visibility, so the answer
     * depends on which kind of channel is being asked for: a workspace may
     * curate its public directory while leaving private channels self-service.
     * Both default to {@see ChannelCreationPolicy::Members}, which is the
     * long-standing "any team member may create" behaviour.
     *
     * `$visibility` is omitted when the question is the affordance-level "is
     * there any channel this user could create?" — the sidebar's New button has
     * no visibility in hand yet — and is answered by either policy letting them
     * through. Membership is required either way.
     */
    public function create(User $user, Team $team, ?ChannelVisibility $visibility = null): bool
    {
        if (! $user->belongsToTeam($team)) {
            return false;
        }

        $role = $user->teamRole($team);

        if ($visibility instanceof ChannelVisibility) {
            return $team->creationPolicyFor($visibility)->permits($role);
        }
        if ($team->creationPolicyFor(ChannelVisibility::Public)->permits($role)) {
            return true;
        }

        return $team->creationPolicyFor(ChannelVisibility::Private)->permits($role);
    }

    /**
     * Determine whether the user can mark the channel as one every new member
     * joins on arrival.
     *
     * Deciding where newcomers land is a workspace-shaping call, so it sits with
     * a team Admin+ rather than with the channel's members. Only a live public
     * channel can be a default: a private one cannot be joined unasked, an
     * archived one is read-only, and #general is already a default in code with
     * no flag to toggle.
     */
    public function setDefault(User $user, Channel $channel): bool
    {
        if ($channel->visibility !== ChannelVisibility::Public) {
            return false;
        }
        if ($channel->isGeneral() || $channel->isArchived()) {
            return false;
        }

        return $this->administers($user, $channel);
    }

    /**
     * Determine whether the user can view the channel.
     *
     * The rule — in a team I belong to, and either public or one I belong to —
     * lives on {@see User::readableChannels()} rather than here, because the REST
     * channel list asks the same question in bulk and the two must never drift
     * apart. This is that query narrowed to one channel.
     */
    public function view(User $user, Channel $channel): bool
    {
        return $user->readableChannels($channel->team)->whereKey($channel->id)->exists();
    }

    /**
     * Determine whether the user can edit the channel's topic and description.
     *
     * These are collaborative, shared-context fields rather than administrative
     * settings, so any member of the channel may change them. A direct message
     * has neither field (it is named by its participants), and an archived
     * channel is read-only, so neither is editable.
     */
    public function update(User $user, Channel $channel): bool
    {
        if ($channel->isDirectMessage() || $channel->isArchived()) {
            return false;
        }

        return $this->isMember($user, $channel);
    }

    /**
     * Determine whether the user can rename the channel.
     *
     * Renaming changes what the whole team calls the channel, so it stays with
     * the people accountable for it: the creator or a team Admin+, and only
     * from within the channel (an Admin joins before they rename).
     */
    public function rename(User $user, Channel $channel): bool
    {
        if (! $this->update($user, $channel)) {
            return false;
        }

        return $channel->created_by === $user->id
            || ($user->teamRole($channel->team)?->isAtLeast(TeamRole::Admin) ?? false);
    }

    /**
     * Determine whether the user can change their own membership of the channel
     * — its notification preferences, its star, its draft, its sidebar placement.
     *
     * These are not four permissions but one: they are all mutations of the
     * membership pivot, so the question each of them asks is the same one, and
     * the answer is that the user is a member of the channel within its team.
     * Each member only ever touches their own row, which is enforced by
     * {@see ChannelMembership} rather than here.
     */
    public function updateMembership(User $user, Channel $channel): bool
    {
        return $this->isMember($user, $channel);
    }

    /**
     * Determine whether the user can close (hide) the direct message from their
     * own sidebar.
     *
     * Only direct messages are hidable — a standard channel leaves the sidebar by
     * archiving, not per-member hiding — and only a member has a pivot row to
     * stamp, which is {@see updateMembership()}. This covers group DMs too:
     * closing hides the row without leaving, distinct from {@see leave()}.
     */
    public function hide(User $user, Channel $channel): bool
    {
        return $channel->isDirectMessage() && $this->updateMembership($user, $channel);
    }

    /**
     * Determine whether the user can post a message to the channel.
     *
     * Only members of a non-archived channel may post.
     */
    public function postMessage(User $user, Channel $channel): bool
    {
        if ($channel->isArchived()) {
            return false;
        }

        return $channel->members()->whereKey($user->id)->exists();
    }

    /**
     * Determine whether the user can join the channel by browsing.
     *
     * Only non-archived public channels are self-joinable, and only by team
     * members. Private channels are invite-only (see addMember).
     */
    public function join(User $user, Channel $channel): bool
    {
        return $user->belongsToTeam($channel->team)
            && $channel->visibility === ChannelVisibility::Public
            && ! $channel->isArchived();
    }

    /**
     * Determine whether the user can leave the channel themselves.
     *
     * A member may leave any standard channel except the protected #general, and
     * may leave a group direct message (the others keep the history and a system
     * notice records the departure). A 1:1 direct message is closed (hidden), not
     * left — see {@see hide()} — so it is never leavable. The last member of a
     * private channel may still leave; we accept orphaning it (only a team Admin+
     * can then repopulate or archive it).
     */
    public function leave(User $user, Channel $channel): bool
    {
        if ($channel->isGeneral() || $channel->isDirect()) {
            return false;
        }

        return $this->isMember($user, $channel);
    }

    /**
     * Determine whether the user can add members to the channel.
     *
     * Private channel membership is managed by existing channel members or by
     * a team Admin+. Public channels are self-service (see join).
     */
    public function addMember(User $user, Channel $channel): bool
    {
        return $this->managesMembership($user, $channel);
    }

    /**
     * Determine whether the user can add people to a direct message.
     *
     * Any participant of a DM may pull more teammates in; the add-people flow
     * opens-or-creates the conversation for the resulting member set (a 1:1 grows
     * into a group, a group grows further, an identical set is reused), so it
     * never mutates an unrelated channel. Only a member of the DM may do so.
     */
    public function addPeople(User $user, Channel $channel): bool
    {
        return $channel->isDirectMessage() && $this->isMember($user, $channel);
    }

    /**
     * Determine whether the user can remove members from the channel.
     */
    public function removeMember(User $user, Channel $channel): bool
    {
        return $this->managesMembership($user, $channel);
    }

    /**
     * Shared rule for the abilities a plain member of the channel holds: they
     * belong to the channel, within its team.
     */
    private function isMember(User $user, Channel $channel): bool
    {
        return $user->belongsToTeam($channel->team)
            && $channel->members()->whereKey($user->id)->exists();
    }

    /**
     * Shared rule for managing a private channel's membership.
     */
    private function managesMembership(User $user, Channel $channel): bool
    {
        if ($channel->visibility !== ChannelVisibility::Private) {
            return false;
        }

        if (! $user->belongsToTeam($channel->team)) {
            return false;
        }
        if ($channel->members()->whereKey($user->id)->exists()) {
            return true;
        }

        return $user->teamRole($channel->team)?->isAtLeast(TeamRole::Admin) ?? false;
    }

    /**
     * Determine whether the user can archive the channel.
     *
     * The #general channel can never be archived. Otherwise the channel's
     * creator or a team Admin+ may archive a non-archived channel.
     */
    public function archive(User $user, Channel $channel): bool
    {
        // Direct messages are never archived — they have no archive affordance and
        // are managed only through the open-or-create flow.
        if ($channel->isGeneral() || $channel->isArchived() || $channel->isDirectMessage()) {
            return false;
        }

        if (! $user->belongsToTeam($channel->team)) {
            return false;
        }

        return $channel->created_by === $user->id
            || ($user->teamRole($channel->team)?->isAtLeast(TeamRole::Admin) ?? false);
    }

    /**
     * Determine whether the user can delete the channel.
     *
     * Deleting is destructive in a way archiving is not — it opens the grace
     * window that ends in the messages, files, and memberships being purged — so
     * it is reserved for a team Admin+, never delegated to the creator the way
     * {@see archive()} is. The #general channel can never be deleted, and a
     * direct message is not an admin's to destroy: it is a private conversation
     * between its participants, who close or leave it instead. A live channel may
     * be deleted directly; archiving first is not required.
     */
    public function delete(User $user, Channel $channel): bool
    {
        if ($channel->isGeneral() || $channel->isDirectMessage()) {
            return false;
        }

        return $this->administers($user, $channel);
    }

    /**
     * Determine whether the user can restore the channel within its grace window.
     *
     * The mirror of {@see delete()}: whoever may open the window may close it
     * again, on the same Admin+ terms, for as long as the channel is still only
     * soft-deleted. Once the purge has run there is no row left to authorize
     * against, so a live channel — nothing to restore — is refused here rather
     * than silently succeeding.
     */
    public function restore(User $user, Channel $channel): bool
    {
        if (! $channel->trashed()) {
            return false;
        }

        return $this->administers($user, $channel);
    }

    /**
     * Shared rule for the workspace-administration abilities on a channel
     * (delete, restore): the user is a team Admin+ of the channel's team.
     * Channel membership is deliberately not required — an admin cleaning up a
     * private channel they never joined still has to be able to.
     */
    private function administers(User $user, Channel $channel): bool
    {
        return $user->belongsToTeam($channel->team)
            && ($user->teamRole($channel->team)?->isAtLeast(TeamRole::Admin) ?? false);
    }
}
