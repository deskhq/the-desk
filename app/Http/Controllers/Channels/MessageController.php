<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\DeleteMessage;
use App\Actions\Channels\EditMessage;
use App\Actions\Channels\PostMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\DeleteMessageRequest;
use App\Http\Requests\Channels\EditMessageRequest;
use App\Http\Requests\Channels\PostMessageRequest;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;

class MessageController extends Controller
{
    /**
     * Post a message to the channel.
     */
    public function store(PostMessageRequest $request, Team $team, Channel $channel, PostMessage $postMessage): RedirectResponse
    {
        $postMessage->handle(
            channel: $channel,
            author: $request->user(),
            body: $request->validated('body'),
            clientUuid: $request->validated('client_uuid'),
            replyToId: $request->validated('reply_to_id'),
            threadRootId: $request->validated('thread_root_id'),
            sentToChannel: $request->boolean('sent_to_channel'),
        );

        return to_route('channels.show', ['team' => $team->slug, 'channel' => $channel->slug]);
    }

    /**
     * Edit the author's own message.
     */
    public function update(EditMessageRequest $request, Team $team, Channel $channel, Message $message, EditMessage $editMessage): RedirectResponse
    {
        $editMessage->handle($channel, $message, $request->validated('body'));

        return to_route('channels.show', ['team' => $team->slug, 'channel' => $channel->slug]);
    }

    /**
     * Soft-delete a message, leaving a tombstone in its place.
     */
    public function destroy(DeleteMessageRequest $request, Team $team, Channel $channel, Message $message, DeleteMessage $deleteMessage): RedirectResponse
    {
        $deleteMessage->handle($channel, $message);

        return to_route('channels.show', ['team' => $team->slug, 'channel' => $channel->slug]);
    }
}
