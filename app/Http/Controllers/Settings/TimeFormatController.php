<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\TimeFormatPreferencesRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class TimeFormatController extends Controller
{
    /**
     * Choose whether times of day render on a 12- or 24-hour clock.
     *
     * Stored on the user so it follows them across devices; the redirect lets
     * Inertia recompute the shared `auth.user` prop, which re-renders every
     * clock in the interface with no full page reload.
     */
    public function update(TimeFormatPreferencesRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Clock style updated.')]);

        return to_route('locale.edit');
    }
}
