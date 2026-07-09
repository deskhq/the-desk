<?php

namespace App\Http\Controllers\Settings;

use App\Enums\ChimeSound;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\NotificationPreferencesRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    /**
     * Show the user's notification settings page.
     */
    public function edit(): Response
    {
        return Inertia::render('settings/Notifications', [
            'chimeSounds' => ChimeSound::options(),
        ]);
    }

    /**
     * Update the user's notification preferences.
     */
    public function update(NotificationPreferencesRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification settings updated.')]);

        return to_route('notifications.edit');
    }
}
