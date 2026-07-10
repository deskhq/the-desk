<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\CancelScheduledMessage;
use App\Actions\Channels\ScheduleMessage;
use App\Actions\Channels\UpdateScheduledMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\CancelScheduledMessageRequest;
use App\Http\Requests\Channels\ScheduleMessageRequest;
use App\Http\Requests\Channels\UpdateScheduledMessageRequest;
use App\Models\Channel;
use App\Models\ScheduledMessage;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;

class ScheduledMessageController extends Controller
{
    /**
     * Schedule a message for future delivery to the channel.
     */
    public function store(ScheduleMessageRequest $request, Team $team, Channel $channel, ScheduleMessage $scheduleMessage): RedirectResponse
    {
        $scheduleMessage->handle(
            channel: $channel,
            author: $request->user(),
            body: $request->validated('body'),
            clientUuid: $request->validated('client_uuid'),
            sendAt: Carbon::parse($request->validated('send_at')),
            replyToId: $request->validated('reply_to_id'),
        );

        return to_route('channels.show', ['team' => $team->slug, 'channel' => $channel->slug]);
    }

    /**
     * Revise a pending scheduled message's body and send time.
     */
    public function update(UpdateScheduledMessageRequest $request, Team $team, Channel $channel, ScheduledMessage $scheduledMessage, UpdateScheduledMessage $updateScheduledMessage): RedirectResponse
    {
        $updateScheduledMessage->handle(
            scheduled: $scheduledMessage,
            body: $request->validated('body'),
            sendAt: Carbon::parse($request->validated('send_at')),
        );

        return to_route('channels.show', ['team' => $team->slug, 'channel' => $channel->slug]);
    }

    /**
     * Cancel a pending scheduled message so it is never delivered.
     */
    public function destroy(CancelScheduledMessageRequest $request, Team $team, Channel $channel, ScheduledMessage $scheduledMessage, CancelScheduledMessage $cancelScheduledMessage): RedirectResponse
    {
        $cancelScheduledMessage->handle($scheduledMessage);

        return to_route('channels.show', ['team' => $team->slug, 'channel' => $channel->slug]);
    }
}
