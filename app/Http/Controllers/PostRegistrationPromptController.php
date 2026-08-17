<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PostRegistrationPrompt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class PostRegistrationPromptController extends Controller
{
    /**
     * Answer the one-time post-registration prompt, whichever way it was answered.
     *
     * Called when the user enrols or picks "Not now". The queued prompt lives in
     * the session persistently rather than flashed, so that a refresh before
     * acting still shows it — which means it needs clearing by hand. Idempotent,
     * so a double dismissal (two tabs, a retried request) is harmless; redirects
     * back and lets Inertia recompute the shared `postRegistrationPrompt` prop.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget(PostRegistrationPrompt::SESSION_KEY);

        return back();
    }
}
