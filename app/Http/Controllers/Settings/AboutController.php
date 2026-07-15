<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class AboutController extends Controller
{
    /**
     * Show the "About this instance" settings page.
     *
     * The running version and update standing ride the shared `update` prop; the
     * page only needs to know whether the update check is enabled, to decide
     * whether to explain the daily check or stay silent (air-gapped instances).
     */
    public function edit(): Response
    {
        return Inertia::render('settings/About', [
            'updateCheckEnabled' => (bool) config('updates.enabled'),
        ]);
    }
}
