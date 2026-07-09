<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReadReceiptsController extends Controller
{
    /**
     * Toggle whether the current user shares their read position with peers.
     *
     * Stored on the user so it follows them across devices; redirects back and
     * lets Inertia recompute the shared `auth.user` prop. When off, the user
     * neither broadcasts nor exposes where they've read up to.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'share_read_receipts' => ['required', 'boolean'],
        ]);

        $request->user()->update($validated);

        return back();
    }
}
