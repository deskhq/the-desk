<?php

namespace App\Http\Controllers\Settings;

use App\Events\UserProfileUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateStatusRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StatusController extends Controller
{
    /**
     * Set the current user's custom status, replacing any previous one.
     *
     * The three columns are always written together, so switching from a status
     * that expired at noon to one that never clears leaves no stale expiry
     * behind. The broadcast lets teammates' open clients pick the new emoji up
     * without a reload.
     */
    public function update(UpdateStatusRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $user = $request->user();

        $user->forceFill([
            'status_emoji' => $validated['emoji'],
            'status_text' => $validated['text'] ?? null,
            'status_expires_at' => $validated['expires_at'] ?? null,
        ])->save();

        event(new UserProfileUpdated($user));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Status updated.')]);

        return back();
    }

    /**
     * Clear the current user's custom status.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill([
            'status_emoji' => null,
            'status_text' => null,
            'status_expires_at' => null,
        ])->save();

        event(new UserProfileUpdated($user));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Status cleared.')]);

        return back();
    }
}
