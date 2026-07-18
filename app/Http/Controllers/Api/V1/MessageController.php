<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Channels\DeleteMessage;
use App\Actions\Channels\EditMessage;
use App\Actions\Channels\PostMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreMessageRequest;
use App\Http\Requests\Api\V1\UpdateMessageRequest;
use App\Http\Resources\Api\V1\MessageResource;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Support\Integrations\ApiChannelAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class MessageController extends Controller
{
    /**
     * List a channel's messages, newest first, paginated.
     */
    public function index(Request $request, Channel $channel): AnonymousResourceCollection
    {
        $subject = $request->user();
        assert($subject instanceof User);

        ApiChannelAccess::assert($subject, $channel);

        $messages = $channel->messages()
            ->with(['user', 'reactions'])
            ->latest()
            ->paginate(50);

        return MessageResource::collection($messages);
    }

    /**
     * Post a message to the channel on behalf of the bot.
     */
    public function store(StoreMessageRequest $request, Channel $channel, PostMessage $postMessage): JsonResponse
    {
        $subject = $request->user();
        assert($subject instanceof User);

        $message = $postMessage->handle(
            channel: $channel,
            author: $subject,
            body: $request->validated('body'),
            clientUuid: $request->validated('client_uuid') ?? (string) Str::uuid(),
            replyToId: $request->validated('reply_to_id'),
            threadRootId: $request->validated('thread_root_id'),
            sentToChannel: $request->boolean('sent_to_channel'),
        );

        $message->load(['user', 'reactions']);

        return MessageResource::make($message)->response()->setStatusCode(201);
    }

    /**
     * Show a single message in one of the bot's channels.
     */
    public function show(Request $request, Channel $channel, Message $message): MessageResource
    {
        $subject = $request->user();
        assert($subject instanceof User);

        ApiChannelAccess::assert($subject, $channel);
        abort_unless($message->channel_id === $channel->id, 404);

        $message->load(['user', 'reactions']);

        return MessageResource::make($message);
    }

    /**
     * Edit one of the bot's own messages.
     */
    public function update(UpdateMessageRequest $request, Channel $channel, Message $message, EditMessage $editMessage): MessageResource
    {
        $message = $editMessage->handle($channel, $message, $request->validated('body'));

        $message->load(['user', 'reactions']);

        return MessageResource::make($message);
    }

    /**
     * Delete one of the bot's own messages, leaving a tombstone.
     */
    public function destroy(Request $request, Channel $channel, Message $message, DeleteMessage $deleteMessage): JsonResponse
    {
        $subject = $request->user();
        assert($subject instanceof User);

        ApiChannelAccess::assert($subject, $channel);
        abort_unless($message->channel_id === $channel->id, 404);
        abort_unless(Gate::allows('delete', $message), 403);

        $deleteMessage->handle($channel, $message);

        return response()->json(null, 204);
    }
}
