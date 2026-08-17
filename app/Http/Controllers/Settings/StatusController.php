<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Users\ClearUserStatus;
use App\Actions\Users\SetUserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateStatusRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class StatusController extends Controller
{
    /**
     * Set the current user's custom status, replacing any previous one.
     */
    public function update(UpdateStatusRequest $request, SetUserStatus $status): RedirectResponse
    {
        $validated = $request->validated();

        $status->handle(
            $this->viewer($request),
            $validated['emoji'],
            $validated['text'] ?? null,
            $validated['expires_at'] ?? null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Status updated')]);

        return back();
    }

    /**
     * Clear the current user's custom status.
     */
    public function destroy(Request $request, ClearUserStatus $status): RedirectResponse
    {
        $status->handle($this->viewer($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Status cleared')]);

        return back();
    }
}
