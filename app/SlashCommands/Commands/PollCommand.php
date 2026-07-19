<?php

declare(strict_types=1);

namespace App\SlashCommands\Commands;

use App\SlashCommands\BaseSlashCommand;
use App\SlashCommands\SlashCommandContext;
use App\SlashCommands\SlashCommandResult;

/**
 * `/poll` — open the poll builder to create a poll in the channel.
 *
 * Registered only when polls are enabled, so it appears in the composer's `/`
 * autocomplete. The interaction is client-side: selecting `/poll` opens the
 * builder panel, and the composed poll is posted as a first-class poll message
 * through its own endpoint — never as text through the command endpoint. This
 * handler is therefore a fallback for a client that submits `/poll` as raw text
 * (e.g. with JavaScript disabled): it returns a hint rather than posting a
 * literal `/poll`.
 */
class PollCommand extends BaseSlashCommand
{
    public function name(): string
    {
        return 'poll';
    }

    public function description(): string
    {
        return __('Create a poll in this channel');
    }

    public function handle(SlashCommandContext $ctx): SlashCommandResult
    {
        return SlashCommandResult::error(__('Use the poll builder to create a poll.'));
    }
}
