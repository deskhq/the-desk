<?php

namespace App\Http\Controllers\Settings;

use App\Enums\ChimeSound;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class AppearanceController extends Controller
{
    /**
     * Show the combined appearance & notifications settings page.
     *
     * Theme is applied client-side from a cookie, so the only server data this
     * page needs is the set of selectable chime sounds.
     */
    public function edit(): Response
    {
        return Inertia::render('settings/Appearance', [
            'chimeSounds' => ChimeSound::options(),
        ]);
    }
}
