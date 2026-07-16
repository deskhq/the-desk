<?php

namespace App\Http\Controllers\Settings;

use App\Enums\SidebarPosition;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SidebarPositionController extends Controller
{
    /**
     * Choose which edge of the workspace the navigation sidebar sits on.
     *
     * Stored on the user so it follows them across devices; redirects back and
     * lets Inertia recompute the shared `auth.user` prop, which re-binds the
     * sidebar's side live with no reload.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sidebar_position' => ['required', Rule::enum(SidebarPosition::class)],
        ]);

        $request->user()->update($validated);

        return back();
    }
}
