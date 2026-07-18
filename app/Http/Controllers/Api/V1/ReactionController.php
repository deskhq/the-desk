<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Channels\ToggleReaction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddReactionRequest;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Support\Integrations\ApiChannelAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReactionController extends Controller
{
    /**
     * Add the bot's reaction to a message (idempotent — re-adding is a no-op).
     */
    public function store(AddReactionRequest $request, Channel $channel, Message $message, ToggleReaction $toggleReaction): JsonResponse
    {
        $subject = $request->user();
        assert($subject instanceof User);

        $emoji = (string) $request->validated('emoji');

        $toggleReaction->add($channel, $message, $subject, $emoji);

        return response()->json(null, 204);
    }

    /**
     * Remove the bot's reaction from a message (idempotent — a missing reaction
     * is a no-op).
     */
    public function destroy(Request $request, Channel $channel, Message $message, ToggleReaction $toggleReaction): JsonResponse
    {
        $subject = $request->user();
        assert($subject instanceof User);

        ApiChannelAccess::assert($subject, $channel);
        abort_unless($message->channel_id === $channel->id, 404);
        abort_unless(Gate::allows('postMessage', $channel) && ! $message->isSystem(), 403);

        $emoji = (string) $request->route('emoji');

        $toggleReaction->remove($channel, $message, $subject, $emoji);

        return response()->json(null, 204);
    }
}
