<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Events\UserProfileUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateDndPauseRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DndController extends Controller
{
    /**
     * Pause the current user's notifications until an instant, replacing any
     * pause already running.
     *
     * The pause lives on the row rather than in the client so it survives a
     * reload, a new device, and a restart until it lapses. The broadcast tells
     * teammates' open clients to paint the DND badge without a reload.
     */
    public function update(UpdateDndPauseRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill(['dnd_until' => Carbon::parse((string) $request->validated('until'))])->save();

        event(new UserProfileUpdated($user));

        return back();
    }

    /**
     * Resume notifications, ending a manual pause early.
     *
     * Only the pause is cleared: the recurring quiet-hours schedule is a
     * standing preference and survives — resuming during quiet hours therefore
     * leaves the user in DND until the window ends, which is what the schedule
     * asked for.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill(['dnd_until' => null])->save();

        event(new UserProfileUpdated($user));

        return back();
    }
}
