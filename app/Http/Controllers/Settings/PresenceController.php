<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Events\UserPresenceChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdatePresenceRequest;
use Illuminate\Http\RedirectResponse;

class PresenceController extends Controller
{
    /**
     * Set — or clear — the current user's manual away override.
     *
     * Away here is an override that outlives the browser: it is stored on the
     * row, so it survives a reconnect, a new device, and a restart until the
     * user unsets it. Clearing it hands the answer back to the live connections,
     * which is why the broadcast carries the *effective* state rather than the
     * one that was just written — a user who unsets away from a tab that has
     * since gone idle stays away, and teammates are told so.
     */
    public function update(UpdatePresenceRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill(['presence_state' => $request->state()])->save();

        event(new UserPresenceChanged($user, $user->effectivePresence()));

        return back();
    }
}
