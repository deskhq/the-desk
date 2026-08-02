<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Users\RemoveUserAvatar;
use App\Actions\Users\StoreUserAvatar;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreAvatarRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AvatarController extends Controller
{
    /**
     * Store a newly uploaded avatar.
     */
    public function store(StoreAvatarRequest $request, StoreUserAvatar $avatar): RedirectResponse
    {
        $avatar->handle($request->user(), $request->file('photo'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Photo updated everywhere')]);

        return back();
    }

    /**
     * Remove the user's uploaded avatar, reverting to the Gravatar → initials
     * fallback.
     */
    public function destroy(Request $request, RemoveUserAvatar $avatar): RedirectResponse
    {
        $avatar->handle($request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Photo removed')]);

        return back();
    }
}
