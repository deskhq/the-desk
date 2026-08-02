<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Users\SetPresenceOverride;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdatePresenceRequest;
use Illuminate\Http\RedirectResponse;

class PresenceController extends Controller
{
    /**
     * Set — or clear — the current user's manual away override.
     */
    public function update(UpdatePresenceRequest $request, SetPresenceOverride $presence): RedirectResponse
    {
        $presence->handle($request->user(), $request->state());

        return back();
    }
}
