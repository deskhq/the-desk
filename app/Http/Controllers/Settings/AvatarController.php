<?php

namespace App\Http\Controllers\Settings;

use App\Events\UserProfileUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreAvatarRequest;
use App\Support\Avatars\AvatarStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AvatarController extends Controller
{
    /**
     * Store a newly uploaded avatar.
     *
     * Processes and stores the new blob, points the user at its cacheable URL,
     * then deletes the previous blob so a replacement leaves no orphan. The
     * broadcast lets every other open client swap the image live.
     */
    public function store(StoreAvatarRequest $request, AvatarStorage $storage): RedirectResponse
    {
        $user = $request->user();
        $previous = $user->avatar_path;

        ['url' => $url, 'path' => $path] = $storage->store($request->file('photo'));

        $user->forceFill(['avatar_url' => $url, 'avatar_path' => $path])->save();

        $storage->delete($previous);

        event(new UserProfileUpdated($user));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Photo updated everywhere.')]);

        return back();
    }

    /**
     * Remove the user's uploaded avatar, reverting to the Gravatar → initials
     * fallback, and clean up its stored blob.
     */
    public function destroy(Request $request, AvatarStorage $storage): RedirectResponse
    {
        $user = $request->user();
        $previous = $user->avatar_path;

        $user->forceFill(['avatar_url' => null, 'avatar_path' => null])->save();

        $storage->delete($previous);

        event(new UserProfileUpdated($user));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Photo removed.')]);

        return back();
    }
}
