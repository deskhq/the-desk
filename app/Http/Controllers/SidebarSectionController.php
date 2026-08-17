<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSidebarSectionsRequest;
use Illuminate\Http\RedirectResponse;

final class SidebarSectionController extends Controller
{
    /**
     * Persist which sidebar sections the current user has collapsed.
     *
     * Stored on the user so the collapsed layout follows them across reloads and
     * devices. Redirects back and lets Inertia recompute the shared
     * `collapsedChannelSections` prop.
     */
    public function update(UpdateSidebarSectionsRequest $request): RedirectResponse
    {
        $collapsed = array_values(array_unique($request->validated('collapsed')));

        $this->viewer($request)->update(['collapsed_channel_sections' => $collapsed]);

        return back();
    }
}
