<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Users\SnoozeDndSchedule;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class DndScheduleSnoozeController extends Controller
{
    /**
     * Snooze the quiet-hours schedule until the running window next closes.
     *
     * The client sends nothing: the lapse instant is the server's own
     * computation from the stored bounds, and outside the window there is
     * nothing to suppress, so the same empty request is answered the same way
     * either way.
     */
    public function update(Request $request, SnoozeDndSchedule $snooze): RedirectResponse
    {
        $snooze->handle($this->viewer($request));

        return back();
    }
}
