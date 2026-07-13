<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\PinMessage;
use App\Actions\Channels\UnpinMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\PinMessageRequest;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;

class PinController extends Controller
{
    /**
     * Pin a message to its channel.
     *
     * The action creates the pin (idempotent) and broadcasts the patch; the pins
     * panel and masthead count update over the broadcast, not the response, so
     * redirecting back keeps the user in the channel — mirroring the reactions
     * controller's convention.
     */
    public function store(PinMessageRequest $request, Team $team, Channel $channel, Message $message, PinMessage $pinMessage): RedirectResponse
    {
        $pinMessage->handle($channel, $message, $request->user());

        return back();
    }

    /**
     * Unpin a message from its channel.
     *
     * Any channel member may unpin (a shared toggle, not pinner-restricted). The
     * action removes the pin (idempotent) and broadcasts the patch.
     */
    public function destroy(PinMessageRequest $request, Team $team, Channel $channel, Message $message, UnpinMessage $unpinMessage): RedirectResponse
    {
        $unpinMessage->handle($channel, $message);

        return back();
    }
}
