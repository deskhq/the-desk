<?php

namespace App\Http\Controllers\Channels;

use App\Actions\Channels\PostMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\StoreSlashCommandRequest;
use App\Models\Channel;
use App\Models\Team;
use App\SlashCommands\ParsedSlashCommand;
use App\SlashCommands\SlashCommandContext;
use App\SlashCommands\SlashCommandDispatcher;
use App\SlashCommands\SlashCommandParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class SlashCommandController extends Controller
{
    /**
     * Run a slash command typed in the composer.
     *
     * The server is authoritative: it parses the raw body itself (the client's
     * parse is only advisory, deciding which endpoint to hit). A body whose
     * leading token is not a registered command is not a command at all, so it
     * is posted verbatim as an ordinary message — the same "no interception, no
     * nag" behaviour a normal send gives. A registered command is dispatched;
     * its result becomes the response: a `postMessage` has already posted (empty
     * redirect back), a `notice` flashes a success toast, and an `error` (or a
     * blocked `authorize`) surfaces as a validation error so the composer keeps
     * the typed text and shows the message as an error toast.
     */
    public function store(
        StoreSlashCommandRequest $request,
        Team $team,
        Channel $channel,
        SlashCommandParser $parser,
        SlashCommandDispatcher $dispatcher,
        PostMessage $postMessage,
    ): RedirectResponse {
        $body = $request->validated('body');
        $parsed = $parser->parse($body);

        if (! $parsed instanceof ParsedSlashCommand) {
            $postMessage->handle(
                channel: $channel,
                author: $request->user(),
                body: $body,
                clientUuid: $request->validated('client_uuid'),
                threadRootId: $request->validated('thread_root_id'),
                sentToChannel: $request->boolean('sent_to_channel'),
            );

            return $this->redirect($team, $channel);
        }

        $result = $dispatcher->dispatch($parsed->command, new SlashCommandContext(
            user: $request->user(),
            team: $team,
            channel: $channel,
            threadRootId: $request->validated('thread_root_id'),
            args: $parsed->args,
            clientUuid: $request->validated('client_uuid'),
            sentToChannel: $request->boolean('sent_to_channel'),
        ));

        if ($result->isError()) {
            throw ValidationException::withMessages(['command' => $result->text]);
        }

        if ($result->isNotice()) {
            return $this->redirect($team, $channel)
                ->with('toast', ['type' => 'success', 'message' => $result->text]);
        }

        // A `postMessage` result has already posted via the dispatcher.
        return $this->redirect($team, $channel);
    }

    /**
     * Redirect back to the channel the command ran in.
     */
    private function redirect(Team $team, Channel $channel): RedirectResponse
    {
        return to_route('channels.show', ['team' => $team->slug, 'channel' => $channel->slug]);
    }
}
