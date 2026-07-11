<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\OpenDirectMessage;
use App\Actions\Channels\PostMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\ForwardMessageRequest;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class ForwardMessageController extends Controller
{
    /**
     * Forward a message into another channel or a direct message.
     *
     * The route scopes `$message` to its source `$channel`; the destination is
     * either the validated `target_channel_id` or, for a `target_user_id`, the
     * 1:1 DM with that teammate (opened or created on forward). The forwarded copy
     * carries an optional note as its body and enters the normal post + broadcast
     * flow in the target. Redirecting back keeps the author on the source channel
     * rather than navigating them to the destination.
     */
    public function store(ForwardMessageRequest $request, Team $team, Channel $channel, Message $message, PostMessage $postMessage, OpenDirectMessage $openDirectMessage): RedirectResponse
    {
        $target = $this->resolveTarget($request, $team, $openDirectMessage);

        $postMessage->handle(
            channel: $target,
            author: $request->user(),
            body: (string) $request->validated('body'),
            clientUuid: $request->validated('client_uuid'),
            forwardedFromId: $message->id,
        );

        return back();
    }

    /**
     * Resolve the forward destination: a chosen channel, or the DM with a chosen
     * teammate (opened or created so a first-time forward starts the conversation).
     */
    private function resolveTarget(ForwardMessageRequest $request, Team $team, OpenDirectMessage $openDirectMessage): Channel
    {
        $targetUserId = $request->validated('target_user_id');

        if ($targetUserId !== null) {
            $targetUser = User::whereKey($targetUserId)->firstOrFail();

            return $openDirectMessage->handle($team, $request->user(), $targetUser);
        }

        return Channel::query()->whereKey($request->validated('target_channel_id'))->firstOrFail();
    }
}
