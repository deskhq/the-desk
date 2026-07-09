<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Sidebar\CreateChannelSection;
use App\Actions\Sidebar\DeleteChannelSection;
use App\Actions\Sidebar\ReorderChannelSections;
use App\Actions\Sidebar\UpdateChannelSection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sidebar\DeleteChannelSectionRequest;
use App\Http\Requests\Sidebar\ReorderChannelSectionsRequest;
use App\Http\Requests\Sidebar\StoreChannelSectionRequest;
use App\Http\Requests\Sidebar\UpdateChannelSectionRequest;
use App\Models\ChannelSection;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;

class ChannelSectionController extends Controller
{
    /**
     * Create a custom sidebar section for the current user in the team.
     *
     * Redirects back and lets Inertia recompute the shared `channelSections` prop
     * so the new section appears without a full reload.
     */
    public function store(StoreChannelSectionRequest $request, Team $team, CreateChannelSection $createChannelSection): RedirectResponse
    {
        $createChannelSection->handle($request->user(), $team, $request->validated('name'));

        return back();
    }

    /**
     * Rename and/or collapse a custom sidebar section.
     */
    public function update(UpdateChannelSectionRequest $request, Team $team, ChannelSection $section, UpdateChannelSection $updateChannelSection): RedirectResponse
    {
        $updateChannelSection->handle($section, $request->validated());

        return back();
    }

    /**
     * Delete a custom sidebar section, returning its channels to the default group.
     */
    public function destroy(DeleteChannelSectionRequest $request, Team $team, ChannelSection $section, DeleteChannelSection $deleteChannelSection): RedirectResponse
    {
        $deleteChannelSection->handle($section);

        return back();
    }

    /**
     * Persist the user's manual order of their custom sections in the team.
     */
    public function reorder(ReorderChannelSectionsRequest $request, Team $team, ReorderChannelSections $reorderChannelSections): RedirectResponse
    {
        $reorderChannelSections->handle($request->user(), $team, $request->validated('sections'));

        return back();
    }
}
