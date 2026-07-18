<?php

declare(strict_types=1);

namespace App\SlashCommands\Commands;

use App\SlashCommands\BaseSlashCommand;
use App\SlashCommands\SlashCommandContext;
use App\SlashCommands\SlashCommandResult;

/**
 * `/shrug [message]` — post the leading text with a shrug appended.
 */
class ShrugCommand extends BaseSlashCommand
{
    public function name(): string
    {
        return 'shrug';
    }

    public function description(): string
    {
        return __('Append a shrug to your message');
    }

    public function argumentHint(): ?string
    {
        return __('[message]');
    }

    public function handle(SlashCommandContext $ctx): SlashCommandResult
    {
        return SlashCommandResult::postMessage($this->appendTo($ctx->args, '¯\_(ツ)_/¯'));
    }
}
