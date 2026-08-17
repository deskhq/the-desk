<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class TimezoneController extends Controller
{
    /**
     * Store the current user's timezone.
     *
     * Called both by the browser's auto-detection on first login and by the
     * manual override in settings. Stored on the user so it follows them across
     * devices; redirects back and lets Inertia recompute the shared `auth.user`.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'timezone' => ['required', 'timezone:all'],
        ]);

        $this->viewer($request)->update($validated);

        return back();
    }
}
