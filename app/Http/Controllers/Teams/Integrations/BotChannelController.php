<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teams\Integrations;

use App\Actions\Channels\JoinChannel;
use App\Actions\Channels\RemoveChannelMember;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\Integrations\StoreBotChannelRequest;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\Team;
use App\Models\User;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Manages which channels a bot belongs to from the integrations surface.
 *
 * Posting is channel-membership-gated: a bot must be a {@see ChannelMember}
 * of a channel to post there (and to back an incoming webhook). Bots aren't
 * `team_members`, so they can't be added through the human channel-member picker
 * without operator intent — this surface, gated on `manageIntegrations`, is that
 * intent.
 */
class BotChannelController extends Controller
{
    /**
     * Add the bot to one of the team's channels.
     */
    public function store(StoreBotChannelRequest $request, Team $team, User $bot, JoinChannel $joinChannel, AuditRecorder $recorder): RedirectResponse
    {
        $this->ensureBotBelongsToTeam($bot, $team);

        /** @var Channel $channel */
        $channel = $team->channels()->whereKey($request->validated('channel_id'))->firstOrFail();

        $joinChannel->handle($channel, $bot);

        $recorder->record($team, $request->user(), AuditAction::ChannelMemberAdded, $channel, [
            'channel_name' => $channel->name,
            'member_name' => $bot->name,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bot added to #:channel.', ['channel' => $channel->name])]);

        return to_route('teams.integrations.bots.show', ['team' => $team->slug, 'bot' => $bot->id]);
    }

    /**
     * Remove the bot from a channel, revoking its posting there.
     */
    public function destroy(Request $request, Team $team, User $bot, Channel $channel, RemoveChannelMember $removeChannelMember, AuditRecorder $recorder): RedirectResponse
    {
        Gate::authorize('manageIntegrations', $team);

        $this->ensureBotBelongsToTeam($bot, $team);

        abort_unless($channel->team_id === $team->id, 404);

        $removeChannelMember->handle($channel, $bot);

        $recorder->record($team, $request->user(), AuditAction::ChannelMemberRemoved, $channel, [
            'channel_name' => $channel->name,
            'member_name' => $bot->name,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bot removed from #:channel.', ['channel' => $channel->name])]);

        return to_route('teams.integrations.bots.show', ['team' => $team->slug, 'bot' => $bot->id]);
    }

    /**
     * Guard that the resolved user really is a bot scoped to this team.
     */
    private function ensureBotBelongsToTeam(User $bot, Team $team): void
    {
        abort_unless($bot->isBot() && $bot->owner_team_id === $team->id, 404);
    }
}
