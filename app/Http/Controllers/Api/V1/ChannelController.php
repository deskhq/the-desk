<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Channels\ArchiveChannel;
use App\Actions\Channels\CreateChannel;
use App\Enums\AuditAction;
use App\Enums\ChannelVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreChannelRequest;
use App\Http\Resources\Api\V1\ChannelResource;
use App\Models\Channel;
use App\Models\User;
use App\Support\AuditRecorder;
use App\Support\Integrations\ApiChannelAccess;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ChannelController extends Controller
{
    /**
     * List the channels the token subject may view within its acting team.
     *
     * A bot sees only the channels it is a member of; a human personal access
     * token sees the same channels the web does — every public channel in the
     * team plus the private ones they belong to.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $subject = $request->user();
        assert($subject instanceof User);

        $team = ApiChannelAccess::team($subject);

        $channels = Channel::query()
            ->where('team_id', $team->id)
            ->where(function (Builder $query) use ($subject): void {
                if ($subject->isBot()) {
                    $query->whereHas('channelMembers', fn (Builder $member) => $member->where('user_id', $subject->id));

                    return;
                }

                $query->where('visibility', ChannelVisibility::Public)
                    ->orWhereHas('channelMembers', fn (Builder $member) => $member->where('user_id', $subject->id));
            })
            ->orderBy('name')
            ->get();

        return ChannelResource::collection($channels);
    }

    /**
     * Create a channel in the subject's acting team; the subject is seeded as its
     * first member.
     */
    public function store(StoreChannelRequest $request, CreateChannel $createChannel, AuditRecorder $recorder): JsonResponse
    {
        $subject = $request->user();
        assert($subject instanceof User);

        $team = ApiChannelAccess::team($subject);

        $channel = $createChannel->handle(
            team: $team,
            name: $request->validated('name'),
            visibility: ChannelVisibility::from($request->validated('visibility')),
            creator: $subject,
            topic: $request->validated('topic'),
        );

        $recorder->record($team, $subject, AuditAction::ChannelCreated, $channel, [
            'channel_name' => $channel->name,
        ]);

        return ChannelResource::make($channel)->response()->setStatusCode(201);
    }

    /**
     * Show a single channel the subject may view.
     */
    public function show(Request $request, Channel $channel): ChannelResource
    {
        $subject = $request->user();
        assert($subject instanceof User);

        ApiChannelAccess::assert($subject, $channel);

        return ChannelResource::make($channel);
    }

    /**
     * Archive a channel.
     *
     * A bot may only archive a channel it created (the human `archive` policy
     * leans on team membership, which a bot lacks), keeping the "not #general /
     * not a DM / not already archived" guards. A human PAT defers to that same
     * web `archive` policy — the channel's creator or a team Admin+ — so the
     * token can never exceed what the person could do in the app.
     */
    public function archive(Request $request, Channel $channel, ArchiveChannel $archiveChannel, AuditRecorder $recorder): ChannelResource
    {
        $subject = $request->user();
        assert($subject instanceof User);

        ApiChannelAccess::assert($subject, $channel);

        if ($subject->isBot()) {
            abort_unless(
                ! $channel->isGeneral()
                    && ! $channel->isArchived()
                    && ! $channel->isDirectMessage()
                    && $channel->created_by === $subject->id,
                403,
            );
        } else {
            abort_unless(Gate::forUser($subject)->allows('archive', $channel), 403);
        }

        $channel = $archiveChannel->handle($channel);

        $recorder->record(ApiChannelAccess::team($subject), $subject, AuditAction::ChannelArchived, $channel, [
            'channel_name' => $channel->name,
        ]);

        return ChannelResource::make($channel);
    }
}
