<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teams\Integrations;

use App\Actions\Integrations\MintBotToken;
use App\Actions\Integrations\RevokeBotToken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\Integrations\StoreBotTokenRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class BotTokenController extends Controller
{
    /**
     * Mint a scoped API token for a bot and reveal its plaintext value once.
     */
    public function store(StoreBotTokenRequest $request, Team $team, User $bot, MintBotToken $mint): RedirectResponse
    {
        $this->ensureBotBelongsToTeam($bot, $team);

        $token = $mint->handle($bot, $request->user(), $request->validated('name'), $request->abilities());

        Inertia::flash('revealed', [
            'kind' => 'bot_token',
            'label' => $request->validated('name'),
            'value' => $token->plainTextToken,
        ]);

        return back();
    }

    /**
     * Revoke one of the bot's tokens immediately.
     */
    public function destroy(Request $request, Team $team, User $bot, string $token, RevokeBotToken $revoke): RedirectResponse
    {
        Gate::authorize('manageIntegrations', $team);

        $this->ensureBotBelongsToTeam($bot, $team);

        $accessToken = $bot->tokens()->whereKey($token)->firstOrFail();

        $revoke->handle($request->user(), $accessToken);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Token revoked.')]);

        return back();
    }

    /**
     * Guard that the resolved user really is a bot scoped to this team.
     */
    private function ensureBotBelongsToTeam(User $bot, Team $team): void
    {
        abort_unless($bot->isBot() && $bot->owner_team_id === $team->id, 404);
    }
}
