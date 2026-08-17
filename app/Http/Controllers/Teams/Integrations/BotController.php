<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teams\Integrations;

use App\Actions\Integrations\CreateBot;
use App\Actions\Integrations\DeleteBot;
use App\Data\BotData;
use App\Data\BotTokenData;
use App\Enums\ChannelType;
use App\Enums\IntegrationScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\Integrations\StoreBotRequest;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class BotController extends Controller
{
    /**
     * Create a bot and land the operator on its detail page to mint a token.
     */
    public function store(StoreBotRequest $request, Team $team, CreateBot $createBot): RedirectResponse
    {
        $bot = $createBot->handle($team, $this->viewer($request), $request->validated('name'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bot created')]);

        return to_route('teams.integrations.bots.show', ['team' => $team->slug, 'bot' => $bot->id]);
    }

    /**
     * Show a bot's detail — its API tokens and the scopes a new token can grant.
     */
    public function show(Request $request, Team $team, User $bot): Response
    {
        $bot->loadCount(['channels', 'tokens'])->loadMax('messages', 'created_at')->load('creator');

        $memberships = $this->botChannels($bot);

        return Inertia::render('teams/integrations/Bot', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
            ],
            'bot' => BotData::fromModel($bot),
            'tokens' => BotTokenData::forBot($bot),
            'scopeOptions' => IntegrationScope::options(),
            'channels' => $memberships,
            'addableChannels' => $this->addableChannels($team, $memberships),
        ]);
    }

    /**
     * Delete a bot, reassigning its history to the tombstone.
     */
    public function destroy(Request $request, Team $team, User $bot, DeleteBot $deleteBot): RedirectResponse
    {
        $deleteBot->handle($this->viewer($request), $bot);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bot deleted')]);

        return to_route('teams.integrations.index', ['team' => $team->slug]);
    }

    /**
     * The standard channels the bot currently belongs to, as id/name/visibility
     * options for the manage page's channel list.
     *
     * @return array<int, array{id: string, name: string, visibility: string}>
     */
    private function botChannels(User $bot): array
    {
        return $bot->channels()
            ->where('type', ChannelType::Standard->value)
            ->orderBy('name')
            ->get(['channels.id', 'name', 'visibility'])
            ->map(fn (Channel $channel): array => [
                'id' => $channel->id,
                'name' => $channel->name,
                'visibility' => $channel->visibility->value,
            ])
            ->all();
    }

    /**
     * The team's standard channels the bot is not yet in — the add-to-channel
     * picker's candidates.
     *
     * @param  array<int, array{id: string, name: string, visibility: string}>  $current
     * @return array<int, array{id: string, name: string, visibility: string}>
     */
    private function addableChannels(Team $team, array $current): array
    {
        $joined = array_column($current, 'id');

        return $team->channels()
            ->where('type', ChannelType::Standard->value)
            ->whereNotIn('id', $joined)
            ->orderBy('name')
            ->get(['id', 'name', 'visibility'])
            ->map(fn (Channel $channel): array => [
                'id' => $channel->id,
                'name' => $channel->name,
                'visibility' => $channel->visibility->value,
            ])
            ->all();
    }
}
