<?php

declare(strict_types=1);

namespace App\SlashCommands\Commands;

use App\SlashCommands\BaseSlashCommand;
use App\SlashCommands\SlashCommandContext;
use App\SlashCommands\SlashCommandResult;

/**
 * `/tableflip [message]` — post the leading text with a flipped table appended.
 */
class TableflipCommand extends BaseSlashCommand
{
    public function name(): string
    {
        return 'tableflip';
    }

    public function description(): string
    {
        return __('Flip the table');
    }

    public function argumentHint(): ?string
    {
        return __('[message]');
    }

    public function handle(SlashCommandContext $ctx): SlashCommandResult
    {
        return SlashCommandResult::postMessage($this->appendTo($ctx->args, '(╯°□°)╯︵ ┻━┻'));
    }
}
