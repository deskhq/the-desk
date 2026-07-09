<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\PostMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\ForwardMessageRequest;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;

class ForwardMessageController extends Controller
{
    /**
     * Forward a message into another channel.
     *
     * The route scopes `$message` to its source `$channel`; the destination is
     * the validated `target_channel_id`. The forwarded copy carries an optional
     * note as its body and enters the normal post + broadcast flow in the target
     * channel. Redirecting back keeps the author on the source channel rather
     * than navigating them to the destination.
     */
    public function store(ForwardMessageRequest $request, Team $team, Channel $channel, Message $message, PostMessage $postMessage): RedirectResponse
    {
        $target = Channel::query()->whereKey($request->validated('target_channel_id'))->firstOrFail();

        $postMessage->handle(
            channel: $target,
            author: $request->user(),
            body: (string) $request->validated('body'),
            clientUuid: $request->validated('client_uuid'),
            forwardedFromId: $message->id,
        );

        return back();
    }
}
