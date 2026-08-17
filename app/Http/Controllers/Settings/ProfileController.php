<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Support\AccountDeleter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'status' => $request->session()->get('status'),
            // Whether the avatar is an uploaded blob (so "Remove photo" applies)
            // rather than a derived Gravatar/initials fallback.
            'hasCustomAvatar' => $this->viewer($request)->avatar_url !== null,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $this->viewer($request)->fill($request->validated());

        if ($this->viewer($request)->isDirty('email')) {
            $this->viewer($request)->email_verified_at = null;
        }

        $this->viewer($request)->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request, AccountDeleter $deleter): RedirectResponse
    {
        $user = $this->viewer($request);

        Auth::logout();

        $deleter->delete($user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
