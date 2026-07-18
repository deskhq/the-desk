<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Channels\JoinChannel;
use App\Actions\Channels\RemoveChannelMember;
use App\Enums\AuditAction;
use App\Enums\ChannelVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddMemberRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\Channel;
use App\Models\User;
use App\Support\AuditRecorder;
use App\Support\Integrations\ApiChannelAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class MemberController extends Controller
{
    /**
     * List the members of a channel the subject may view.
     */
    public function index(Request $request, Channel $channel): AnonymousResourceCollection
    {
        $subject = $request->user();
        assert($subject instanceof User);

        ApiChannelAccess::assert($subject, $channel);

        $members = $channel->members()->orderBy('name')->get();

        return UserResource::collection($members);
    }

    /**
     * Add a team member to one of the subject's private channels.
     */
    public function store(AddMemberRequest $request, Channel $channel, JoinChannel $joinChannel, AuditRecorder $recorder): JsonResponse
    {
        $subject = $request->user();
        assert($subject instanceof User);

        $user = User::findOrFail((string) $request->validated('user_id'));

        $joinChannel->handle($channel, $user);

        $recorder->record(ApiChannelAccess::team($subject), $subject, AuditAction::ChannelMemberAdded, $channel, [
            'channel_name' => $channel->name,
            'member_name' => $user->name,
        ]);

        return UserResource::make($user)->response()->setStatusCode(201);
    }

    /**
     * Remove a member from one of the subject's private channels.
     *
     * A bot may manage membership on any private channel it belongs to; a human
     * PAT defers to the same web `removeMember` policy — an existing member of
     * the private channel, or a team Admin+ — so the token never exceeds what the
     * person could do in-app.
     */
    public function destroy(Request $request, Channel $channel, User $user, RemoveChannelMember $removeChannelMember, AuditRecorder $recorder): JsonResponse
    {
        $subject = $request->user();
        assert($subject instanceof User);

        ApiChannelAccess::assert($subject, $channel);

        if ($subject->isBot()) {
            abort_unless($channel->visibility === ChannelVisibility::Private, 403);
        } else {
            abort_unless(Gate::forUser($subject)->allows('removeMember', $channel), 403);
        }

        abort_unless($channel->channelMembers()->where('user_id', $user->id)->exists(), 404);

        $removeChannelMember->handle($channel, $user);

        $recorder->record(ApiChannelAccess::team($subject), $subject, AuditAction::ChannelMemberRemoved, $channel, [
            'channel_name' => $channel->name,
            'member_name' => $user->name,
        ]);

        return response()->json(null, 204);
    }
}
