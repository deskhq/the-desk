<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\NotificationPreferencesRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final class NotificationController extends Controller
{
    /**
     * Update the user's notification preferences.
     *
     * Notification preferences now live on the combined appearance &
     * notifications page, so the redirect lands there.
     */
    public function update(NotificationPreferencesRequest $request): RedirectResponse
    {
        $this->viewer($request)->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification settings updated')]);

        return to_route('appearance.edit');
    }
}
