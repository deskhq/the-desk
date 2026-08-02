<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Users\PauseNotifications;
use App\Actions\Users\ResumeNotifications;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateDndPauseRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DndController extends Controller
{
    /**
     * Pause the current user's notifications until an instant.
     */
    public function update(UpdateDndPauseRequest $request, PauseNotifications $pause): RedirectResponse
    {
        $pause->handle($request->user(), Carbon::parse((string) $request->validated('until')));

        return back();
    }

    /**
     * Resume notifications, ending a manual pause early.
     */
    public function destroy(Request $request, ResumeNotifications $resume): RedirectResponse
    {
        $resume->handle($request->user());

        return back();
    }
}
