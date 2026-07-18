<?php

declare(strict_types=1);

namespace App\SlashCommands\Commands;

use App\SlashCommands\BaseSlashCommand;
use App\SlashCommands\SlashCommandContext;
use App\SlashCommands\SlashCommandResult;

/**
 * `/gif [search]` — open the Giphy picker to send a GIF.
 *
 * Registered only when Giphy is configured, so it appears in the composer's `/`
 * autocomplete. The interaction is client-side: selecting `/gif` opens the
 * picker, and the chosen GIF is sent as a remote attachment through the ordinary
 * attachment flow — never as text through the command endpoint. This handler is
 * therefore a fallback for a client that submits `/gif` as raw text (e.g. with
 * JavaScript disabled): it returns a hint rather than posting a literal `/gif`.
 */
class GifCommand extends BaseSlashCommand
{
    public function name(): string
    {
        return 'gif';
    }

    public function description(): string
    {
        return __('Search Giphy for a GIF to send');
    }

    public function argumentHint(): ?string
    {
        return __('[search]');
    }

    public function handle(SlashCommandContext $ctx): SlashCommandResult
    {
        return SlashCommandResult::error(__('Pick a GIF from the picker to send one.'));
    }
}
