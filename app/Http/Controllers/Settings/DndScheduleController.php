<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Users\SetDndSchedule;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateDndScheduleRequest;
use Illuminate\Http\RedirectResponse;

class DndScheduleController extends Controller
{
    /**
     * Set the current user's recurring quiet-hours window.
     */
    public function update(UpdateDndScheduleRequest $request, SetDndSchedule $schedule): RedirectResponse
    {
        $validated = $request->validated();

        $schedule->handle(
            $request->user(),
            (bool) $validated['enabled'],
            $validated['starts_at'] ?? null,
            $validated['ends_at'] ?? null,
        );

        return back();
    }
}
