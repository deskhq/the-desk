<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\ClearMessageReminder;
use App\Actions\Channels\SetMessageReminder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\ClearMessageReminderRequest;
use App\Http\Requests\Channels\SetMessageReminderRequest;
use App\Models\MessageReminder;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MessageReminderController extends Controller
{
    /**
     * Set (or re-arm) a personal reminder on a message.
     */
    public function store(SetMessageReminderRequest $request, Team $team, SetMessageReminder $setMessageReminder): RedirectResponse
    {
        $setMessageReminder->handle(
            user: $request->user(),
            message: $request->reminderMessage(),
            remindAt: Carbon::parse($request->validated('remind_at')),
        );

        return back();
    }

    /**
     * Clear a single reminder (a pending one, or an acknowledged nudge).
     */
    public function destroy(ClearMessageReminderRequest $request, Team $team, MessageReminder $reminder, ClearMessageReminder $clearMessageReminder): RedirectResponse
    {
        $clearMessageReminder->handle($reminder);

        return back();
    }

    /**
     * Clear all of the user's still-pending reminders in this team.
     *
     * The delete is scoped to the caller's own rows, so no policy is needed:
     * the authenticated user can only ever clear reminders they set.
     */
    public function destroyAll(Request $request, Team $team): RedirectResponse
    {
        MessageReminder::query()
            ->where('user_id', $request->user()->id)
            ->pending()
            ->whereHas('message.channel', fn (Builder $query) => $query->where('team_id', $team->id))
            ->delete();

        return back();
    }
}
