<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Onboarding\CompleteOnboarding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class OnboardingController extends Controller
{
    /**
     * Mark the current user's first-run onboarding tour as complete.
     *
     * Called when the user finishes or dismisses the tour. Stored on the user so
     * the auto-starting tour and brand-new-workspace welcome stay dismissed across
     * devices; redirects back and lets Inertia recompute the shared `auth.user`
     * prop.
     */
    public function update(Request $request, CompleteOnboarding $completeOnboarding): RedirectResponse
    {
        $completeOnboarding->handle($this->viewer($request));

        return back();
    }
}
