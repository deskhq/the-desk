<?php

namespace App\Http\Controllers\Settings;

use App\Enums\AppLocale;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\LocalePreferencesRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class LocaleController extends Controller
{
    /**
     * Show the user's localization settings page.
     */
    public function edit(): Response
    {
        return Inertia::render('settings/Localization', [
            'locales' => AppLocale::options(),
        ]);
    }

    /**
     * Update the user's locale preference.
     */
    public function update(LocalePreferencesRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Language updated.')]);

        return to_route('locale.edit');
    }
}
