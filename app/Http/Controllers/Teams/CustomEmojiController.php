<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\CreateCustomEmoji;
use App\Actions\Teams\RevokeCustomEmoji;
use App\Data\CustomEmojiData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\StoreCustomEmojiRequest;
use App\Models\CustomEmoji;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CustomEmojiController extends Controller
{
    /**
     * Show the workspace's custom emoji registry.
     */
    public function index(Request $request, Team $team): Response
    {
        Gate::authorize('view', $team);

        return Inertia::render('teams/Emojis', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
            ],
            'emojis' => CustomEmojiData::forTeam($team),
            'permissions' => [
                'canManageEmojis' => $request->user()->toTeamPermissions($team)->canManageEmojis,
            ],
        ]);
    }

    /**
     * Register a newly uploaded custom emoji.
     */
    public function store(StoreCustomEmojiRequest $request, Team $team, CreateCustomEmoji $createCustomEmoji): RedirectResponse
    {
        $createCustomEmoji->handle(
            $team,
            $request->user(),
            $request->validated('name'),
            $request->file('image'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Emoji added.')]);

        return back();
    }

    /**
     * Remove a custom emoji — the uploader deletes their own, an admin revokes any.
     */
    public function destroy(Request $request, Team $team, CustomEmoji $emoji, RevokeCustomEmoji $revokeCustomEmoji): RedirectResponse
    {
        abort_unless($emoji->team_id === $team->id, 404);

        Gate::authorize('delete', $emoji);

        $revokeCustomEmoji->handle($team, $request->user(), $emoji);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Emoji removed.')]);

        return back();
    }
}
