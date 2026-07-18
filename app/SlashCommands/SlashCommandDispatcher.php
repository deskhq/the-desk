<?php

declare(strict_types=1);

namespace App\SlashCommands;

use App\Actions\Channels\PostMessage;

/**
 * The single interpreter of a command run. It gates the command through its own
 * `authorize()`, invokes the pure handler, and — for a `postMessage` result —
 * performs the one side effect by routing the text through {@see PostMessage},
 * so a command-posted message inherits mention resolution, broadcast, echo, and
 * client_uuid dedup exactly like an ordinary send. `notice` and `error` results
 * carry no side effect; the caller renders them as toasts.
 */
class SlashCommandDispatcher
{
    public function __construct(private readonly PostMessage $postMessage) {}

    public function dispatch(SlashCommand $command, SlashCommandContext $ctx): SlashCommandResult
    {
        if (! $command->authorize($ctx)) {
            return SlashCommandResult::error(
                __('You are not allowed to use the :command command.', ['command' => '/'.$command->name()]),
            );
        }

        $result = $command->handle($ctx);

        if ($result->isPostMessage()) {
            $this->postMessage->handle(
                channel: $ctx->channel,
                author: $ctx->user,
                body: $result->text,
                clientUuid: $ctx->clientUuid,
                threadRootId: $ctx->threadRootId,
                sentToChannel: $ctx->sentToChannel,
            );
        }

        return $result;
    }
}
