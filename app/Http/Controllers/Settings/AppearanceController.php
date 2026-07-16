<?php

namespace App\Http\Controllers\Settings;

use App\Enums\ChimeSound;
use App\Enums\SidebarPosition;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class AppearanceController extends Controller
{
    /**
     * Show the combined appearance & notifications settings page.
     *
     * Theme is applied client-side from a cookie; the sidebar position rides on
     * the shared `auth.user` prop, so the page only needs the set of selectable
     * chime sounds and the selectable sidebar positions for their pickers.
     */
    public function edit(): Response
    {
        return Inertia::render('settings/Appearance', [
            'chimeSounds' => ChimeSound::options(),
            'sidebarPositions' => SidebarPosition::options(),
        ]);
    }
}
