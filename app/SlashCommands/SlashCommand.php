<?php

declare(strict_types=1);

namespace App\SlashCommands;

use App\Providers\SlashCommandServiceProvider;

/**
 * The contract every slash command implements. A command is a self-describing
 * unit: it names itself (the token typed after `/`), describes itself for the
 * autocomplete manifest, gates its own use, and — purely — returns a
 * {@see SlashCommandResult} the dispatcher interprets.
 *
 * Commands register explicitly into the {@see SlashCommandRegistry} (via
 * {@see SlashCommandServiceProvider}); there is no
 * auto-discovery, so a command surface never registers itself silently.
 */
interface SlashCommand
{
    /**
     * The bare command name, without the leading slash (e.g. `shrug`). Must be
     * unique in the registry and match `\w+` so it parses unambiguously.
     */
    public function name(): string;

    /**
     * A short, already-translated description shown in the autocomplete row.
     */
    public function description(): string;

    /**
     * An already-translated hint for the command's arguments (e.g. `[message]`),
     * or null when the command takes none.
     */
    public function argumentHint(): ?string;

    /**
     * Whether `$ctx->user` may run this command here. Returns false to block it:
     * `handle()` never runs and the invoker sees a permission error toast.
     */
    public function authorize(SlashCommandContext $ctx): bool;

    /**
     * Run the command and return its result. Must be pure — build a
     * {@see SlashCommandResult}; never post, broadcast, or mutate directly.
     */
    public function handle(SlashCommandContext $ctx): SlashCommandResult;
}
