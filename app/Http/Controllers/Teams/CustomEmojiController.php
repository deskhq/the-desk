<?php

declare(strict_types=1);

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

final class CustomEmojiController extends Controller
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
                'canManageEmojis' => $this->viewer($request)->toTeamPermissions($team)->canManageEmojis,
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
            $this->viewer($request),
            $request->validated('name'),
            $request->file('image'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Emoji added')]);

        return back();
    }

    /**
     * Remove a custom emoji — the uploader deletes their own, an admin revokes any.
     */
    public function destroy(Request $request, Team $team, CustomEmoji $customEmoji, RevokeCustomEmoji $revokeCustomEmoji): RedirectResponse
    {
        Gate::authorize('delete', $customEmoji);

        $revokeCustomEmoji->handle($team, $this->viewer($request), $customEmoji);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Emoji removed')]);

        return back();
    }
}
