<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Events\UserProfileUpdated;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DndScheduleSnoozeController extends Controller
{
    /**
     * Snooze the quiet-hours schedule until the running window next closes.
     *
     * The lapse instant is the server's own computation from the stored bounds
     * — the client sends nothing — so the snooze can never outlive tonight's
     * window: once it passes the schedule resumes on its own, with no
     * re-enable step. Outside the window there is nothing to suppress, so the
     * request changes nothing and announces nothing. The broadcast tells
     * teammates' open clients to drop the DND badge without a reload.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $closesAt = $user->dndScheduleClosesAt();

        if ($closesAt === null) {
            return back();
        }

        $user->forceFill(['dnd_schedule_snoozed_until' => $closesAt])->save();

        event(new UserProfileUpdated($user));

        return back();
    }
}
