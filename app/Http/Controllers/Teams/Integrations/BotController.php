<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teams\Integrations;

use App\Actions\Integrations\CreateBot;
use App\Actions\Integrations\DeleteBot;
use App\Data\BotData;
use App\Data\BotTokenData;
use App\Enums\IntegrationScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\Integrations\StoreBotRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BotController extends Controller
{
    /**
     * Create a bot and land the operator on its detail page to mint a token.
     */
    public function store(StoreBotRequest $request, Team $team, CreateBot $createBot): RedirectResponse
    {
        $bot = $createBot->handle($team, $request->user(), $request->validated('name'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bot created.')]);

        return to_route('teams.integrations.bots.show', ['team' => $team->slug, 'bot' => $bot->id]);
    }

    /**
     * Show a bot's detail — its API tokens and the scopes a new token can grant.
     */
    public function show(Request $request, Team $team, User $bot): Response
    {
        Gate::authorize('manageIntegrations', $team);

        $this->ensureBotBelongsToTeam($bot, $team);

        $bot->loadCount(['channels', 'tokens'])->loadMax('messages', 'created_at')->load('creator');

        return Inertia::render('teams/integrations/Bot', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
            ],
            'bot' => BotData::fromModel($bot),
            'tokens' => BotTokenData::forBot($bot),
            'scopeOptions' => IntegrationScope::options(),
        ]);
    }

    /**
     * Delete a bot, reassigning its history to the tombstone.
     */
    public function destroy(Request $request, Team $team, User $bot, DeleteBot $deleteBot): RedirectResponse
    {
        Gate::authorize('manageIntegrations', $team);

        $this->ensureBotBelongsToTeam($bot, $team);

        $deleteBot->handle($request->user(), $bot);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bot deleted.')]);

        return to_route('teams.integrations.index', ['team' => $team->slug]);
    }

    /**
     * Guard that the resolved user really is a bot scoped to this team.
     */
    private function ensureBotBelongsToTeam(User $bot, Team $team): void
    {
        abort_unless($bot->isBot() && $bot->owner_team_id === $team->id, 404);
    }
}
