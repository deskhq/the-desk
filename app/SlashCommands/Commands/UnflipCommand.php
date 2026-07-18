<?php

declare(strict_types=1);

namespace App\SlashCommands\Commands;

use App\SlashCommands\BaseSlashCommand;
use App\SlashCommands\SlashCommandContext;
use App\SlashCommands\SlashCommandResult;

/**
 * `/unflip [message]` — post the leading text with a restored table appended.
 */
class UnflipCommand extends BaseSlashCommand
{
    public function name(): string
    {
        return 'unflip';
    }

    public function description(): string
    {
        return __('Put the table back');
    }

    public function argumentHint(): ?string
    {
        return __('[message]');
    }

    public function handle(SlashCommandContext $ctx): SlashCommandResult
    {
        return SlashCommandResult::postMessage($this->appendTo($ctx->args, '┬─┬ ノ( ゜-゜ノ)'));
    }
}
