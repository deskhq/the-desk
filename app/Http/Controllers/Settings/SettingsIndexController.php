<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\SessionRegistry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsIndexController extends Controller
{
    /**
     * Show the settings index — the list every settings surface is reached
     * from below the breakpoint. Everything the list renders rides on the
     * shared props except the active-session count for the Security row.
     */
    public function index(Request $request, SessionRegistry $registry): Response
    {
        return Inertia::render('settings/Index', [
            'sessionsCount' => count($registry->all($request->user()->id)),
        ]);
    }
}
